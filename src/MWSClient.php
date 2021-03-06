<?php
namespace MCS;

use DateTime;
use Exception;
use DateTimeZone;
use MCS\MWSEndPoint;
use League\Csv\Reader;
use League\Csv\Writer;
use SplTempFileObject;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\BadResponseException;
use Spatie\ArrayToXml\ArrayToXml;

class MWSClient{

    const SIGNATURE_METHOD = 'HmacSHA256';
    const SIGNATURE_VERSION = '2';
    const DATE_FORMAT = "Y-m-d\TH:i:s.\\0\\0\\0\\Z";
    const APPLICATION_NAME = 'MCS/MwsClient';

    private $config = [
        'Seller_Id' => null,
        'Marketplace_Id' => null,
        'Access_Key_ID' => null,
        'Secret_Access_Key' => null,
        'MWSAuthToken' => null,
        'Application_Version' => '0.0.*'
    ];

    private $MarketplaceIds = [
        'A2EUQ1WTGCTBG2' => 'mws.amazonservices.ca',
        'ATVPDKIKX0DER' => 'mws.amazonservices.com',
        'A1AM78C64UM0Y8' => 'mws.amazonservices.com.mx',
        'A1PA6795UKMFR9' => 'mws-eu.amazonservices.com',
        'A1RKKUPIHCS9HS' => 'mws-eu.amazonservices.com',
        'A13V1IB3VIYZZH' => 'mws-eu.amazonservices.com',
        'A21TJRUUN4KGV' => 'mws.amazonservices.in',
        'APJ6JRA9NG5V4' => 'mws-eu.amazonservices.com',
        'A1F83G8C2ARO7P' => 'mws-eu.amazonservices.com',
        'A1VC38T7YXB528' => 'mws.amazonservices.jp',
        'AAHKV2X7AFYLW' => 'mws.amazonservices.com.cn',
        'A39IBJ37TRP1C6' => 'mws.amazonservices.com.au',
        'A2Q3Y263D00KWC' => 'mws.amazonservices.com',
        'A1805IZSGTT6HS' => 'mws-eu.amazonservices.com',
    ];

    protected $debugNextFeed = false;
    protected $client = NULL;

    public function __construct(array $config)
    {

        foreach($config as $key => $value) {
            if (array_key_exists($key, $this->config)) {
                $this->config[$key] = $value;
            }
        }

        $required_keys = [
            'Marketplace_Id', 'Seller_Id', 'Access_Key_ID', 'Secret_Access_Key'
        ];

        foreach ($required_keys as $key) {
            if(is_null($this->config[$key])) {
                throw new Exception('Required field ' . $key . ' is not set');
            }
        }

        if (is_array($this->config['Marketplace_Id'])) {
            foreach ($this->config['Marketplace_Id'] as $marketPlaceId) {
                if (!isset($this->MarketplaceIds[$marketPlaceId])) {
                    throw new Exception('Invalid Marketplace Id');
                }
            }
        } else {
            if (!isset($this->MarketplaceIds[$this->config['Marketplace_Id']])) {
                throw new Exception('Invalid Marketplace Id');
            }
        }

        $this->config['Application_Name'] = self::APPLICATION_NAME;
        if (is_array($this->config['Marketplace_Id'])) {
            $this->config['Region_Host'] = $this->MarketplaceIds[$this->config['Marketplace_Id'][0]];
        } else {
            $this->config['Region_Host'] = $this->MarketplaceIds[$this->config['Marketplace_Id']];
        }
        $this->config['Region_Url'] = 'https://' . $this->config['Region_Host'];

    }

    /**
     * Call this method to get the raw feed instead of sending it
     */
    public function debugNextFeed()
    {
        $this->debugNextFeed = true;
    }

    /**
     * A method to quickly check if the supplied credentials are valid
     * @return boolean
     */
    public function validateCredentials()
    {
        try{
            $this->ListOrderItems('validate');
        } catch(Exception $e) {
            if ($e->getMessage() == 'Invalid AmazonOrderId: validate') {
                return true;
            } else {
                return false;
            }
        }
    }

    /**
     * Returns orders created or updated during a time frame that you specify.
     * @param object DateTime $from
     * @param boolean $allMarketplaces, list orders from all marketplaces
     * @param array $states, an array containing orders states you want to filter on
     * @param string $FulfillmentChannel
     * @return array
     */
    public function ListOrders(DateTime $from, $allMarketplaces = false, $states = [
        'Unshipped', 'PartiallyShipped'
    ], $FulfillmentChannels = 'MFN')
    {
        $query = [
            'CreatedAfter' => gmdate(self::DATE_FORMAT, $from->getTimestamp())
        ];

        $counter = 1;
        foreach ($states as $status) {
            $query['OrderStatus.Status.' . $counter] = $status;
            $counter = $counter + 1;
        }

        if ($allMarketplaces == true) {
            $counter = 1;
            foreach($this->MarketplaceIds as $key => $value) {
                $query['MarketplaceId.Id.' . $counter] = $key;
                $counter = $counter + 1;
            }
        }

        if (is_array($FulfillmentChannels)) {
            $counter = 1;
            foreach ($FulfillmentChannels as $fulfillmentChannel) {
                $query['FulfillmentChannel.Channel.' . $counter] = $fulfillmentChannel;
                $counter = $counter + 1;
            }
        } else {
            $query['FulfillmentChannel.Channel.1'] = $FulfillmentChannels;
        }

        $response = $this->request('ListOrders', $query);

        if (isset($response['ListOrdersResult']['Orders']['Order'])) {
            if (isset($response['ListOrdersResult']['NextToken'])) {
                $data['ListOrders'] = $response['ListOrdersResult']['Orders']['Order'];
                $data['NextToken'] = $response['ListOrdersResult']['NextToken'];
                return $data;
            }

            $response = $response['ListOrdersResult']['Orders']['Order'];

            if (array_keys($response) !== range(0, count($response) - 1)) {
                return [$response];
            }

            return $response;

        } else {
            return [];
        }
    }

    /**
     * Returns orders created or updated during a time frame that you specify.
     * @param string $nextToken
     * @return array
     */
    public function ListOrdersByNextToken($nextToken)
    {
        $query = [
            'NextToken' => $nextToken,
        ];

        $response = $this->request(
            'ListOrdersByNextToken',
            $query
        );
        if (isset($response['ListOrdersByNextTokenResult']['Orders']['Order'])) {
            if(isset($response['ListOrdersByNextTokenResult']['NextToken'])){
                $data['ListOrders'] = $response['ListOrdersByNextTokenResult']['Orders']['Order'];
                $data['NextToken'] = $response['ListOrdersByNextTokenResult']['NextToken'];
                return $data;
            }
            $response = $response['ListOrdersByNextTokenResult']['Orders']['Order'];

            if (array_keys($response) !== range(0, count($response) - 1)) {
                return [$response];
            }
            return $response;
        } else {
            return [];
        }
    }
    /**
     * Returns an order based on the AmazonOrderId values that you specify.
     * @param string $AmazonOrderId
     * @return array if the order is found, false if not
     */
    public function GetOrder($AmazonOrderId)
    {
        $response = $this->request('GetOrder', [
            'AmazonOrderId.Id.1' => $AmazonOrderId
        ]);

        if (isset($response['GetOrderResult']['Orders']['Order'])) {
            return $response['GetOrderResult']['Orders']['Order'];
        } else {
            return false;
        }
    }

    /**
     * Retrieve multiple orders at once
     *
     * @param $AmazonOrderIds
     * @return array|false|mixed|string|string[]
     * @throws Exception
     */
    public function GetOrders($AmazonOrderIds)
    {
        $query = [];
        $counter = 1;
        foreach ($AmazonOrderIds as $AmazonOrderId) {
            $query['AmazonOrderId.Id.'. $counter] = $AmazonOrderId;
            $counter++;
        }

        $response = $this->request('GetOrder', $query);

        if (isset($response['GetOrderResult']['Orders']['Order'])) {
            if (isset($response['GetOrderResult']['Orders']['Order']['OrderType'])) {
                return [$response['GetOrderResult']['Orders']['Order']];
            }
            return $response['GetOrderResult']['Orders']['Order'];
        } else {
            return false;
        }
    }

    /**
     * Returns order items based on the AmazonOrderId that you specify.
     * @param string $AmazonOrderId
     * @return array
     */
    public function ListOrderItems($AmazonOrderId)
    {
        $response = $this->request('ListOrderItems', [
            'AmazonOrderId' => $AmazonOrderId
        ]);

        $result = array_values($response['ListOrderItemsResult']['OrderItems']);

        if (isset($result[0]['QuantityOrdered'])) {
            return $result;
        } else {
            return $result[0];
        }
    }

    /**
     * Returns a list of reports that were created in the previous 90 days.
     * @param array [$ReportTypeList = []]
     * @return array
     */
    public function GetReportList($ReportTypeList = [])
    {
        $array = [];
        $counter = 1;
        if (count($ReportTypeList)) {
            foreach($ReportTypeList as $ReportType) {
                $array['ReportTypeList.Type.' . $counter] = $ReportType;
                $counter++;
            }
        }

        return $this->request('GetReportList', $array);
    }

    /**
     * Returns a list of marketplaces that the seller submitting the request can sell in, and a list of participations that include seller-specific information in that marketplace
     * @return array
     */
    public function ListMarketplaceParticipations()
    {
        $result = $this->request('ListMarketplaceParticipations');
        if (isset($result['ListMarketplaceParticipationsResult'])) {
            return $result['ListMarketplaceParticipationsResult'];
        } else {
            return $result;
        }
    }

    /**
     * Update a product's stock quantity
     * @param array $array array containing sku as key and quantity as value
     * @return array feed submission result
     */
    public function updateStock(array $array)
    {
        $feed = [
            'MessageType' => 'Inventory',
            'Message' => []
        ];

        foreach ($array as $sku => $quantity) {
            $feed['Message'][] = [
                'MessageID' => rand(),
                'OperationType' => 'Update',
                'Inventory' => [
                    'SKU' => $sku,
                    'Quantity' => (int) $quantity
                ]
            ];
        }

        return $this->SubmitFeed('_POST_INVENTORY_AVAILABILITY_DATA_', $feed);

    }
    /**
     * Update a product's stock quantity
     * @param array $array array containing sku as key and quantity as value
     * @return array feed submission result
     */
    public function updateStockNew(array $stockRecords)
    {
        $feed = [
            'MessageType' => 'Inventory',
            'Message' => []
        ];

        $counter = 1;
        foreach ($stockRecords as $warehouseId => $warehouseStock) {
            foreach ($warehouseStock as $stockRecord) {
                $feed['Message'][] = [
                    'MessageID' => $counter,
                    'OperationType' => 'Update',
                    'Inventory' => [
                        'SKU' => $stockRecord['sku'],
                        'Quantity' => $stockRecord['stock'],
                    ]
                ];
                $counter++;
            }
        }

        return $this->SubmitFeed('_POST_INVENTORY_AVAILABILITY_DATA_', $feed);

    }

    /**
     * Update a product's stock quantity
     *
     * @param array $array array containing arrays with next keys: [sku, quantity, latency]
     * @return array feed submission result
     */
    public function updateStockWithFulfillmentLatency(array $array)
    {
        $feed = [
            'MessageType' => 'Inventory',
            'Message' => []
        ];

        foreach ($array as $item) {
            $feed['Message'][] = [
                'MessageID' => rand(),
                'OperationType' => 'Update',
                'Inventory' => [
                    'SKU' => $item['sku'],
                    'Quantity' => (int) $item['quantity'],
                    'FulfillmentLatency' => $item['latency']
                ]
            ];
        }

        return $this->SubmitFeed('_POST_INVENTORY_AVAILABILITY_DATA_', $feed);
    }

    /**
     * Update a product's price
     * @param array $standardprice an array containing sku as key and price as value
     * @param array $salesprice an optional array with sku as key and value consisting of an array with key/value pairs for SalePrice, StartDate, EndDate
     * Dates in DateTime object
     * Price has to be formatted as XSD Numeric Data Type (http://www.w3schools.com/xml/schema_dtypes_numeric.asp)
     * @return array feed submission result
     */
    public function updatePrice(array $standardprice, array $saleprice = null) {

        $feed = [
            'MessageType' => 'Price',
            'Message' => []
        ];

        foreach ($standardprice as $sku => $price) {
            $feed['Message'][] = [
                'MessageID' => rand(),
                'Price' => [
                    'SKU' => $sku,
                    'StandardPrice' => [
                        '_value' => strval($price),
                        '_attributes' => [
                            'currency' => 'DEFAULT'
                        ]
                    ]
                ]
            ];

            if (isset($saleprice[$sku]) && is_array($saleprice[$sku])) {
                $feed['Message'][count($feed['Message']) - 1]['Price']['Sale'] = [
                    'StartDate' => $saleprice[$sku]['StartDate']->format(self::DATE_FORMAT),
                    'EndDate' => $saleprice[$sku]['EndDate']->format(self::DATE_FORMAT),
                    'SalePrice' => [
                        '_value' => strval($saleprice[$sku]['SalePrice']),
                        '_attributes' => [
                            'currency' => 'DEFAULT'
                        ]]
                ];
            }
        }

        return $this->SubmitFeed('_POST_PRODUCT_PRICING_DATA_', $feed);
    }

    /**
     * Post to create or update a product (_POST_FLAT_FILE_LISTINGS_DATA_)
     * @param  object $MWSProduct or array of MWSProduct objects
     * @return array
     */
    public function postProduct($MWSProduct) {

        if (!is_array($MWSProduct)) {
            $MWSProduct = [$MWSProduct];
        }

        $csv = Writer::createFromFileObject(new SplTempFileObject());

        $csv->setDelimiter("\t");
        $csv->setInputEncoding('iso-8859-1');

        $csv->insertOne(['TemplateType=Offer', 'Version=2014.0703']);

        $header = ['sku', 'price', 'quantity', 'product-id',
            'product-id-type', 'condition-type', 'condition-note',
            'ASIN-hint', 'title', 'product-tax-code', 'operation-type',
            'sale-price', 'sale-start-date', 'sale-end-date', 'leadtime-to-ship',
            'launch-date', 'is-giftwrap-available', 'is-gift-message-available',
            'fulfillment-center-id', 'main-offer-image', 'offer-image1',
            'offer-image2', 'offer-image3', 'offer-image4', 'offer-image5'
        ];

        $csv->insertOne($header);
        $csv->insertOne($header);

        foreach ($MWSProduct as $product) {
            $csv->insertOne(
                array_values($product->toArray())
            );
        }

        return $this->SubmitFeed('_POST_FLAT_FILE_LISTINGS_DATA_', $csv);

    }

    /**
     * Returns the feed processing report and the Content-MD5 header.
     * @param string $FeedSubmissionId
     * @return array
     */
    public function GetFeedSubmissionResult($FeedSubmissionId)
    {
        $result = $this->request('GetFeedSubmissionResult', [
            'FeedSubmissionId' => $FeedSubmissionId
        ]);

        if (isset($result['Message']['ProcessingReport'])) {
            return $result['Message']['ProcessingReport'];
        } else {
            return $result;
        }
    }

    /**
     * Uploads a feed for processing by Amazon MWS.
     * @param string $FeedType (http://docs.developer.amazonservices.com/en_US/feeds/Feeds_FeedType.html)
     * @param mixed $feedContent Array will be converted to xml using https://github.com/spatie/array-to-xml. Strings will not be modified.
     * @param boolean $debug Return the generated xml and don't send it to amazon
     * @return array
     */
    public function SubmitFeed($FeedType, $feedContent, $debug = false, $options = [])
    {

        if (is_array($feedContent)) {
            $feedContent = $this->arrayToXml(
                array_merge([
                    'Header' => [
                        'DocumentVersion' => 1.01,
                        'MerchantIdentifier' => $this->config['Seller_Id']
                    ]
                ], $feedContent)
            );
        }

        if ($debug === true) {
            return $feedContent;
        } else if ($this->debugNextFeed == true) {
            $this->debugNextFeed = false;
            return $feedContent;
        }

        $purgeAndReplace = isset($options['PurgeAndReplace']) ? $options['PurgeAndReplace'] : false;

        $query = [
            'FeedType' => $FeedType,
            'PurgeAndReplace' => ($purgeAndReplace ? 'true' : 'false'),
            'Merchant' => $this->config['Seller_Id'],
            'MarketplaceId.Id.1' => false,
            'SellerId' => false,
        ];

        //if ($FeedType === '_POST_PRODUCT_PRICING_DATA_') {
        if (is_array($this->config['Marketplace_Id'])) {
            foreach ($this->config['Marketplace_Id'] as $key => $marketPlaceId) {
                $counter = $key + 1;
                $query['MarketplaceIdList.Id.'. $counter] = $marketPlaceId;
            }
        } else {
            $query['MarketplaceIdList.Id.1'] = $this->config['Marketplace_Id'];
        }
        //}

        $response = $this->request(
            'SubmitFeed',
            $query,
            $feedContent
        );

        return $response['SubmitFeedResult']['FeedSubmissionInfo'];
    }

    /**
     * Convert an array to xml
     * @param $array array to convert
     * @param $customRoot [$customRoot = 'AmazonEnvelope']
     * @return sting
     */
    private function arrayToXml(array $array, $customRoot = 'AmazonEnvelope')
    {
        return ArrayToXml::convert($array, $customRoot);
    }

    /**
     * Convert an xml string to an array
     * @param string $xmlstring
     * @return array
     */
    private function xmlToArray($xmlstring)
    {
        return json_decode(json_encode(simplexml_load_string($xmlstring)), true);
    }

    /**
     * Creates a report request and submits the request to Amazon MWS.
     * @param string $report (http://docs.developer.amazonservices.com/en_US/reports/Reports_ReportType.html)
     * @param DateTime [$StartDate = null]
     * @param EndDate [$EndDate = null]
     * @return string ReportRequestId
     */
    public function RequestReport($report, $StartDate = null, $EndDate = null)
    {
        if (is_array($this->config['Marketplace_Id'])) {
            $query = ['ReportType' => $report];
            foreach ($this->config['Marketplace_Id'] as $key => $marketPlaceId) {
                $counter = $key + 1;
                $query['MarketplaceIdList.Id.'. $counter] = $marketPlaceId;
            }
        } else {
            $query = [
                'MarketplaceIdList.Id.1' => $this->config['Marketplace_Id'],
                'ReportType' => $report
            ];
        }

        if (!is_null($StartDate)) {
            if (!is_a($StartDate, 'DateTime')) {
                throw new Exception('StartDate should be a DateTime object');
            } else {
                $query['StartDate'] = gmdate(self::DATE_FORMAT, $StartDate->getTimestamp());
            }
        }

        if (!is_null($EndDate)) {
            if (!is_a($EndDate, 'DateTime')) {
                throw new Exception('EndDate should be a DateTime object');
            } else {
                $query['EndDate'] = gmdate(self::DATE_FORMAT, $EndDate->getTimestamp());
            }
        }

        $result = $this->request(
            'RequestReport',
            $query
        );

        if (isset($result['RequestReportResult']['ReportRequestInfo']['ReportRequestId'])) {
            return $result['RequestReportResult']['ReportRequestInfo']['ReportRequestId'];
        } else {
            throw new Exception('Error trying to request report');
        }
    }

    /**
     * Get a report's content
     * @param string $ReportId
     * @return array on succes
     */
    public function GetReport($ReportId)
    {
        // $status = $this->GetReportRequestStatus($ReportId);

        // if ($status !== false && $status['ReportProcessingStatus'] === '_DONE_NO_DATA_') {
        //     return [];
        // } else if ($status !== false && $status['ReportProcessingStatus'] === '_DONE_') {

        $result = $this->request('GetReport', [
            'ReportId' => $ReportId
        ]);

        if (is_string($result)) {
            $csv = Reader::createFromString($result);
            $csv->setDelimiter("\t");
            $headers = $csv->fetchOne();
            $result = [];
            foreach ($csv->setOffset(1)->fetchAll() as $row) {
                $result[] = array_combine($headers, $row);
            }
        }

        return $result;

        // } else {
        //     return false;
        // }
    }

    /**
     * Get a report's processing status
     * @param string  $ReportId
     * @return array if the report is found
     */
    public function GetReportRequestStatus($ReportId)
    {
        $result = $this->request('GetReportRequestList', [
            'ReportRequestIdList.Id.1' => $ReportId
        ]);

        if (isset($result['GetReportRequestListResult']['ReportRequestInfo'])) {
            return $result['GetReportRequestListResult']['ReportRequestInfo'];
        }

        return false;

    }

    /**
     * Get a report's processing status
     * @param string  $ReportId
     * @return array if the report is found
     */
    public function GetReportRequestStatuses($reportIds = [])
    {
        $reports = [];
        foreach ($reportIds as $key => $reportId) {
            $counter = $key + 1;
            $reports['ReportRequestIdList.Id.'. $counter] = $reportId;
        }

        $result = $this->request('GetReportRequestList', $reports);

        if (isset($result['GetReportRequestListResult']['ReportRequestInfo'])) {
            return $result['GetReportRequestListResult']['ReportRequestInfo'];
        }

        return false;
    }

    /**
     * Request MWS
     */
    private function request($endPoint, array $query = [], $body = null, $raw = false)
    {

        $endPoint = MWSEndPoint::get($endPoint);

        $merge = [
            'Timestamp' => gmdate(self::DATE_FORMAT, time()),
            'AWSAccessKeyId' => $this->config['Access_Key_ID'],
            'Action' => $endPoint['action'],
            //'MarketplaceId.Id.1' => $this->config['Marketplace_Id'],
            'SellerId' => $this->config['Seller_Id'],
            'SignatureMethod' => self::SIGNATURE_METHOD,
            'SignatureVersion' => self::SIGNATURE_VERSION,
            'Version' => $endPoint['date'],
        ];

        $query = array_merge($merge, $query);

        if (!isset($query['MarketplaceId.Id.1'])) {
            if (is_array($this->config['Marketplace_Id'])) {
                foreach ($this->config['Marketplace_Id'] as $key => $marketplaceId) {
                    $counter = $key + 1;
                    $query['MarketplaceId.Id.'. $counter] = $marketplaceId;
                }
            } else {
                $query['MarketplaceId.Id.1'] = $this->config['Marketplace_Id'];
            }
        }

        if (!is_null($this->config['MWSAuthToken']) and $this->config['MWSAuthToken'] != "") {
            $query['MWSAuthToken'] = $this->config['MWSAuthToken'];
        }

        // doesn't seem to be possible...
        if (isset($query['MarketplaceId'])) {
            unset($query['MarketplaceId.Id.1']);
        }

        if (isset($query['MarketplaceIdList.Id.1'])) {
            if (is_array($this->config['Marketplace_Id'])) {
                foreach ($this->config['Marketplace_Id'] as $key => $marketplaceId) {
                    $counter = $key + 1;
                    if (isset($query['MarketplaceId.Id.' . $counter])) {
                        unset($query['MarketplaceId.Id.' . $counter]);
                    }
                }
            } else {
                unset($query['MarketplaceId.Id.1']);
            }
        }

        try{

            $headers = [
                'Accept' => 'application/xml',
                'x-amazon-user-agent' => $this->config['Application_Name'] . '/' . $this->config['Application_Version']
            ];

            if ($endPoint['action'] === 'SubmitFeed') {
                $headers['Content-MD5'] = base64_encode(md5($body, true));
                $headers['Content-Type'] = 'text/xml; charset=iso-8859-1';
                $headers['Host'] = $this->config['Region_Host'];

                unset(
                    $query['MarketplaceId.Id.1'],
                    $query['SellerId']
                );
            }

            $requestOptions = [
                'headers' => $headers,
                'body' => $body
            ];

            ksort($query);

            $query['Signature'] = base64_encode(
                hash_hmac(
                    'sha256',
                    $endPoint['method']
                    . "\n"
                    . $this->config['Region_Host']
                    . "\n"
                    . $endPoint['path']
                    . "\n"
                    . http_build_query($query, null, '&', PHP_QUERY_RFC3986),
                    $this->config['Secret_Access_Key'],
                    true
                )
            );

            $requestOptions['query'] = $query;

            if($this->client === NULL) {
                $this->client = new Client();
            }

            $response = $this->client->request(
                $endPoint['method'],
                $this->config['Region_Url'] . $endPoint['path'],
                $requestOptions
            );



            $body = (string) $response->getBody();


            if ($raw) {
                return $body;
            } else if (strpos(strtolower($response->getHeader('Content-Type')[0]), 'xml') !== false) {
                return $this->xmlToArray($body);
            } else {
                return $body;
            }

        } catch (BadResponseException $e) {
            if ($e->hasResponse()) {
                $message = $e->getResponse();
                $message = $message->getBody();
                if (strpos($message, '<ErrorResponse') !== false) {
                    $error = simplexml_load_string($message);
                    $message = $error->Error->Message;
                }
            } else {
                $message = 'An error occured';
            }
            throw new Exception($message);
        }
    }

    public function setClient(Client $client) {
        $this->client = $client;
    }
}

<?php 

namespace OpenHome;

use GuzzleHttp\Client;
use GuzzleHttp\Promise;
use GuzzleHttp\Exception\ClientException;
use GuzzleHttp\Exception\ServerException;
use GuzzleHttp\Exception\ConnectException;
use Redis;

/**
 * Miot OpenHome API
 * 
 * Docs: 
 * 小米IOT协议规范
 * https://iot.mi.com/new/guide.html?file=07-%E4%BA%91%E5%AF%B9%E4%BA%91%E5%BC%80%E5%8F%91%E6%8C%87%E5%8D%97/02-%E5%BA%94%E7%94%A8%E4%BA%91%E5%AF%B9%E4%BA%91%E6%8E%A5%E5%85%A5/01-%E5%B0%8F%E7%B1%B3IOT%E5%8D%8F%E8%AE%AE%E8%A7%84%E8%8C%83
 * 
 * 小米IOT控制端API
 * https://iot.mi.com/new/guide.html?file=07-%E4%BA%91%E5%AF%B9%E4%BA%91%E5%BC%80%E5%8F%91%E6%8C%87%E5%8D%97/02-%E5%BA%94%E7%94%A8%E4%BA%91%E5%AF%B9%E4%BA%91%E6%8E%A5%E5%85%A5/02-%E5%B0%8F%E7%B1%B3IOT%E6%8E%A7%E5%88%B6%E7%AB%AFAPI
 */
class MiotOpenHome
{
    protected $appId;
    protected $accessToken;
    protected $requestId;

    protected static $instanceUrl = 'https://miot-spec.org/miot-spec-v2/instance?type=';
    protected static $protocol = 'http://';
    protected static $servers = [
        'cn' => 'api.home.mi.com', 
        'us' => 'us.api.home.mi.com',
        'sg' => 'sg.api.home.mi.com',
        'de' => 'de.api.home.mi.com',
        'ru' => 'ru.api.home.mi.com',
        'in' => 'i2.api.home.mi.com',
    ];

    protected static $propertiesApi = '/api/v1/properties';

    public function __construct( $appId = null, $accessToken = null )
    {
        $this->appId       = $appId;
        $this->accessToken = $accessToken;
        $this->requestId   = uniqid();
    }

    /**
     * Get Properties (读取属性)
     * @author Scott Yu  <yusureyes@gmail.com>  http://yusure.cn
     * @date   2019-01-24
     * @param  [type]     $region [Region 标识]
     * @param  array      $did    [设备ID数组]
     * @return [type]             [description]
     */
    public function query( $region, array $dids )
    {
        $pids = $this->transferPid( $dids );
        $url = $this->buildpropertiesApi( $region );

        $client = new Client();
        $result = $client->request( 'GET', $url, [
            'headers' => $this->buildHeaders(),
            'query'   => ['pid' => $pids],
            'timeout' => 3
        ]);

        $response = json_decode( $result->getBody(), true );        
        var_dump( $response );die;


    }

    public function control()
    {

    }

    private function buildHeaders()
    {
        return [
            'App-Id'       => $this->appId,
            'Access-Token' => $this->accessToken,
            'Spec-NS'      => 'miot-spec-v2',
            'Content-Type' => 'application/json',
            'Request-Id'   => $this->requestId,
        ];
    }

    private function buildpropertiesApi( $region )
    {
        $servers = self::$servers;
        $url = self::$protocol . $servers[ $region ] . self::$propertiesApi;

        return $url;
    }

    /**
     * did to pid
     * @author Scott Yu  <yusureyes@gmail.com>  http://yusure.cn
     * @date   2019-01-25
     * @param  [type]     $dids [多个设备ID]
     */
    private function transferPid( $dids )
    {
        foreach ( $dids as $did )
        {
            /* 查找 type */
            $type = $this->getMiotType( $did );

            /* 根据 type 找到 spec 文档 */
            $spec = $this->getInstanceSpec( $type );

            /* 在 spec 文档里面查找 */
            $pids = $this->pickProperties( $did, $spec, 'read' );
        }

        return $pids;
    }

    private function getMiotType( $did )
    {
        $type = Redis::hget( 'miot:did:type', $did );
        if ( ! $type )
        {
            /* 容错，保留个默认 type */
            $type = 'urn:miot-spec-v2:device:light:0000A001:yeelink-color1:1';
        }

        return $type;
    }

    /**
     * type to spec
     * @author Scott Yu  <yusureyes@gmail.com>  http://yusure.cn
     * @date   2019-01-25
     * @param  [type]     $type [description]
     * @return [type]           [description]
     */
    private function getInstanceSpec( $type )
    {
        $spec = Redis::hget( 'miot:instance:spec', $type );
        if ( ! $spec )
        {
            $url = self::$instanceUrl . $type;
            $spec = file_get_contents( $url );
            Redis::hset( 'miot:instance:spec', $type, $spec );
        }
        $instanceSpec = json_decode( $spec, true );

        return $instanceSpec;
    }

    /**
     * 取出 siid + piid，组装 pid
     * 属性ID = 设备ID + 服务实例ID + 属性实例ID
     * <PID> ::= <DID>"."<SIID>"."<PIID>
     */
    private function pickProperties( $did, $spec, $accessType )
    {
        $pid_set = [];
        $services = $spec['services'];
        foreach ( $services as $k => $service )
        {
            $properties = $service['properties'];
            foreach ( $properties as $pro_k => $property )
            {
                if ( in_array( $accessType, $property['access'] ) )
                {
                    /* Assemble pid */
                    $piid = $property['iid'];
                    $siid = $service['iid'];
                    $pid = $did . '.' . $siid . '.' . $piid;

                    array_push( $pid_set, $pid );
                }
            }
        }

        return $pid_set;
    }

}
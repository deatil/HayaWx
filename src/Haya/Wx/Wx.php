<?php

namespace Haya\Wx;

/**
 * web端微信协议
 *
 * @create 2017-9-1
 * @author deatil
 */
class Wx
{
	// 微信appid
	private $appid = 'wx782c26e4c19acffb';
	
	// CURL保存cookie地址
	private $cookieJar = '';
	
	// 微信上传地址
	private $uploadUri = 'https://file.wx.qq.com/cgi-bin/mmwebwx-bin';
	
	// 计数器
	private $mediaCount = -1;
	
	// 边界
	private $boundary = '';
	
	/**
	 * 构造函数
	 *
	 * @create 2017-9-1
	 * @author deatil
	 */
	public function __construct()
	{
		$this->cookieJar = tempnam(dirname(__DIR__).'/temp/cookie', 'cookie');
	}
	
	/**
	 * 获取毫秒
	 * 
	 * @return string
	 */
	protected function getMillisecond()
	{
		list($t1, $t2) = explode(' ', microtime());
		return $t2 . ceil(($t1 * 1000));
	}

	/**
	 * 获取唯一的uuid用于生成二维码
	 *
	 * @return $uuid
	 */
	public function getUuid()
	{
		$url = 'https://login.weixin.qq.com/jslogin';
		$url .= '?appid=' . $this->appid;
		$url .= '&fun=new';
		$url .= '&lang=zh_CN';
		$url .= '&_=' . time();

		$content = $this->curlPost($url);
		//也可以使用正则匹配
		$content = explode(';', $content);

		$content_uuid = explode('"', $content[1]);

		$uuid = $content_uuid[1];

		return $uuid;
	}

	/**
	 * 生成二维码
	 *
	 * @param $uuid
	 * @return img
	 */
	public function qrcode($uuid)
	{
		$url = 'https://login.weixin.qq.com/qrcode/' . $uuid . '?t=webwx';
		$img = "<img class='img' src=" . $url . "/>";
		return $img;
	}

	/**
	 * 扫描登录
	 * 
	 * @param $uuid
	 * @param string $icon
	 * @return array code 
	 * 408:未扫描; 201:扫描未登录; 200:登录成功; icon:用户头像
	 */
	public function login($uuid, $icon = 'true')
	{
		$url = 'https://login.weixin.qq.com/cgi-bin/mmwebwx-bin/login?loginicon=' . $icon . '&r=' . ~time() . '&uuid=' . $uuid . '&tip=0&_=' . $this->getMillisecond();
		$content = $this->curlPost($url);
		preg_match('/\d+/', $content, $match);
		$code = $match[0];
		preg_match('/([\'"])([^\'"\.]*?)\1/', $content, $icon);
		$user_icon = $icon[2];
		if ($user_icon) {
			$data = array(
				'code' => $code,
				'icon' => $user_icon,
			);
		} else {
			$data['code'] = $code;
		}
		echo json_encode($data);
	}

	/**
	 * 登录成功回调
	 * 
	 * @param $uuid
	 * @return array $callback
	 */
	public function getUri($uuid)
	{
		$url = 'https://login.weixin.qq.com/cgi-bin/mmwebwx-bin/login?uuid=' . $uuid . '&tip=0&_=e' . time();
		$content = $this->curlPost($url);
		$content = explode(';', $content);
		$content_uri = explode('"', $content[1]);
		$uri = $content_uri[1];

		preg_match("~^https:?(//([^/?#]*))?~", $uri, $match);
		$https_header = $match[0];
		$post_url_header = $https_header . "/cgi-bin/mmwebwx-bin";
		$_SESSION['https_header'] = $https_header;
		
		$new_uri = explode('scan', $uri);
		$uri = $new_uri[0] . 'fun=new&scan=' . time();
		$getXML = $this->curlPost($uri);

		$XML = simplexml_load_string($getXML);
		$XML = json_decode(json_encode($XML), true);
		
		$callback = array(
			'post_url_header' => $post_url_header,
			'Ret' => (array) $XML,
		);
		return $callback;
	}

	/**
	 * 获取post数据
	 * 
	 * @param array $callback
	 * @return object $post
	 */
	public function postSelf($callback)
	{
		$post = new stdClass;
		$Ret = $callback['Ret'];
		$status = $Ret['ret'];
		if ($status == '1203') {
			$post->error = '未知错误,请2小时后重试';
			return $post;
		}
		if ($status == '0') {
			$post->BaseRequest = array(
				'Uin' => $Ret['wxuin'],
				'Sid' => $Ret['wxsid'],
				'Skey' => $Ret['skey'],
				'DeviceID' => 'e' . rand(10000000, 99999999) . rand(1000000, 9999999),
			);

			$post->skey = $Ret['skey'];

			$post->pass_ticket = $Ret['pass_ticket'];

			$post->sid = $Ret['wxsid'];

			$post->uin = $Ret['wxuin'];

			return $post;
		}
		
		return $post;
	}

	/**
	 * 初始化
	 *
	 * @param $post
	 * @return json $json
	 */
	public function wxinit($post)
	{
		$url = $_SESSION['https_header'] . '/cgi-bin/mmwebwx-bin/webwxinit?pass_ticket=' . $post->pass_ticket . '&skey=' . $post->skey . '&r=' . time();

		$post = array(
			'BaseRequest' => $post->BaseRequest,
		);
		$json = $this->curlPost($url, $post);

		return $json;
	}

	/**
	 * 获取 MsgId
	 *
	 * @param $post
	 * @param $json
	 * @param $post_url_header
	 * @return array $data
	 */
	public function wxstatusnotify($post, $json, $post_url_header)
	{
		$init = json_decode($json, true);

		$User = $init['User'];
		$url = $post_url_header . '/webwxstatusnotify?lang=zh_CN&pass_ticket=' . $post->pass_ticket;

		$params = array(
			'BaseRequest' => $post->BaseRequest,
			"Code" => 3,
			"FromUserName" => $User['UserName'],
			"ToUserName" => $User['UserName'],
			"ClientMsgId" => time()
		);

		$data = $this->curlPost($url, $params);

		$data = json_decode($data, true);

		return $data;
	}

	/**
	 * 获取联系人
	 *
	 * @param $post
	 * @param $post_url_header
	 * @return array $data
	 */
	public function webwxgetcontact($post, $post_url_header)
	{
		$url = $post_url_header . '/webwxgetcontact?pass_ticket=' . $post->pass_ticket . '&seq=0&skey=' . $post->skey . '&r=' . time();

		$params['BaseRequest'] = $post->BaseRequest;

		$data = $this->curlPost($url, $params);

		$data = json_decode($data, true);

		return $data;
	}

	/**
	 * 获取当前活跃群信息
	 *
	 * @param $post
	 * @param $post_url_header
	 * @param $group_list 从获取联系人和初始化中获取
	 * @return array $data
	 */
	public function webwxbatchgetcontact(
		$post, 
		$post_url_header, 
		$group_list
	) {
		$url = $post_url_header . '/webwxbatchgetcontact?type=ex&lang=zh_CN&r=' . time() . '&pass_ticket=' . $post->pass_ticket;

		$params['BaseRequest'] = $post->BaseRequest;

		$params['Count'] = count($group_list);

		foreach ($group_list as $key => $value) {
			if ($value['MemberCount'] == 0) {
				$params['List'][] = array(
					'UserName' => $value['UserName'],
					'ChatRoomId' => "",
				);
			} else {
				$params['List'][] = array(
					'UserName' => $value['UserName'],
					'EncryChatRoomId' => "",
				);
			}
		}

		$data = $this->curlPost($url, $params);

		$data = json_decode($data, true);

		return $data;
	}

	/**
	 * 心跳检测 
	 * 0 正常；
	 * 1101失败／登出；
	 * 2 新消息；
	 * 7 不要耍手机了我都收不到消息了；
	 *
	 * @param $post
	 * @param $SyncKey 初始化方法中获取
	 * @return array $status
	 */
	public function synccheck($post, $SyncKey)
	{
		if (!$SyncKey['List']) {
			$SyncKey = $_SESSION['json']['SyncKey'];
		}
		foreach ($SyncKey['List'] as $key => $value) {
			if ($key == 1) {
				$SyncKey_value = $value['Key'] . '_' . $value['Val'];
			} else {
				$SyncKey_value .= '|' . $value['Key'] . '_' . $value['Val'];
			}

		}

		$header = array(
			'0' => 'https://webpush.wx2.qq.com',
			'1' => 'https://webpush.wx.qq.com',
		);

		foreach ($header as $key => $value) {

			$url = $value . "/cgi-bin/mmwebwx-bin/synccheck?r=" . $this->getMillisecond() . "&skey=" . urlencode($post->skey) . "&sid=" . $post->sid . "&deviceid=" . $post->BaseRequest['DeviceID'] . "&uin=" . $post->uin . "&synckey=" . urlencode($SyncKey_value) . "&_=" . $this->getMillisecond();

			$data[] = $this->curlPost($url);
		}

		foreach ($data as $k => $val) {

			$rule = '/window.synccheck={retcode:"(\d+)",selector:"(\d+)"}/';

			preg_match($rule, $data[$k], $match);

			if ($match[1] == '0') {
				$retcode = $match[1];
				$selector = $match[2];
			}
		}

		$status = array(
			'ret' => $retcode,
			'sel' => $selector,
		);

		return $status;
	}

	/**
	 * 获取最新消息
	 * 
	 * @param $post
	 * @param $post_url_header
	 * @param $SyncKey
	 * @return array $data
	 */
	public function webwxsync($post, $post_url_header, $SyncKey)
	{
		$url = $post_url_header . '/webwxsync?sid=' . $post->sid . '&skey=' . $post->skey . '&pass_ticket=' . $post->pass_ticket;

		$params = array(
			'BaseRequest' => $post->BaseRequest,
			'SyncKey' => $SyncKey,
			'rr' => ~time(),
		);
		$data = $this->curlPost($url, $params);

		$data = json_decode($data, true);

		return $data;
	}

	/**
	 * 发送消息
	 * 
	 * @param $post
	 * @param $post_url_header
	 * @param $to 发送人
	 * @param $word
	 * @return array $data
	 */
	public function webwxsendmsg(
		$post, 
		$post_url_header, 
		$to, 
		$word
	) {
		header("Content-Type: text/html; charset=UTF-8");

		$url = $post_url_header . '/webwxsendmsg?pass_ticket=' . $post->pass_ticket;

		$clientMsgId = $this->getMillisecond() * 1000 + rand(1000, 9999);

		$params = array(
			'BaseRequest' => $post->BaseRequest,
			'Msg' => array(
				"Type" => 1,
				"Content" => $word,
				"FromUserName" => $post->User['UserName'],
				"ToUserName" => $to,
				"LocalID" => $clientMsgId,
				"ClientMsgId" => $clientMsgId
			),
			'Scene' => 0,
		);

		$data = $this->curlPost($url, $params, 1);
		$data = json_decode($data, true);

		return $data;
	}
	
	/**
	 * 上传媒体
	 * 
	 * @param $post
	 * @param $ToUserName
	 * @param $image_name
	 * @return bool
	 */
    public function webwxuploadmedia(
		$post, 
		$ToUserName, 
		$image_name
	) {		
        $url = $this->uploadUri . '/webwxuploadmedia?f=json';
        // 计数器
        $media_count = ++$this->mediaCount;
        // 文件名
        $file_name = $image_name;
        // MIME格式
        // mime_type = application/pdf, image/jpeg, image/png, etc.
        // $mime_type = mime_content_type($image_name);
		$mime_type = $this->mime_content_type($image_name);
        // 微信识别的文档格式，微信服务器应该只支持两种类型的格式。pic和doc
        // pic格式，直接显示。doc格式则显示为文件。
        $media_type = explode('/', $mime_type)[0] == 'image' ? 'pic' : 'doc';
        $fTime = filemtime($image_name);
        // 上一次修改日期
        $lastModifieDate = gmdate('D M d Y H:i:s TO', $fTime ).' (中国标准时间)';//'Thu Mar 17 2016 00:55:10 GMT+0800 (CST)';
        // 文件大小
        $file_size = filesize($file_name);
        // PassTicket
        $pass_ticket = $post->pass_ticket;
        // clientMediaId
        $client_media_id = (time() * 1000).mt_rand(10000,99999);
        // webwx_data_ticket
        $webwx_data_ticket = '';
        $fp = fopen($this->cookieJar, 'r');
        while ($line = fgets($fp)) {
            if (strpos($line, 'webwx_data_ticket') !== false) {
                $arr = explode("\t", trim($line));
                $webwx_data_ticket = $arr[6];
                break;
            }
        }
        fclose($fp);

        $uploadmediarequest = $this->json_encode(array(
            "BaseRequest" => $post->BaseRequest,
            "ClientMediaId" => $client_media_id,
            "TotalLen" => $file_size,
            "StartPos" => 0,
            "DataLen" => $file_size,
            "MediaType" => 4,
            "UploadType" => 2,
            "FromUserName" => $post->User["UserName"],
            "ToUserName" => $ToUserName,
            "FileMd5" => md5_file($image_name)
        ));

        $multipart_encoder = array(
            'id' => 'WU_FILE_' . $media_count,
            'name' => basename($file_name),
            'type' => $mime_type,
            'lastModifieDate'=> $lastModifieDate,
            'size' => $file_size,
            'mediatype' => $media_type,
            'uploadmediarequest' => $uploadmediarequest,
            'webwx_data_ticket' => $webwx_data_ticket,
            'pass_ticket' => $pass_ticket,
            'filename' => '@'.realpath($file_name)
        );

        $response = $this->weixinPost($url, $multipart_encoder, false, true);
        $response_json = json_decode($response, true);
		
        if ($response_json['BaseResponse']['Ret'] == 0) {
            return $response_json;
		}
		
        return null;
    }
	
	/**
	 * 获取格式
	 *
	 * @param string $file 文件
	 * @return string 
	 * 
	 * @create 2017-9-1
	 * @author deatil
	 */
	protected function mime_content_type($file)
	{
		// FILEINFO_MIME, FILEINFO_MIME_TYPE
        $finfo = finfo_open(FILEINFO_MIME); 
        $mime = finfo_file($finfo, $file);
		finfo_close($finfo);
		
		$new = preg_match('/([^;]+);?.*$/', $mime, $match);
		if ($new) {
			$mime = trim($match[1]);
		}
		
		return $mime;
	}

    /**
     * 获取媒体类型
     *
     * @param $file
     * @return array
     */
    private function getMediaType($file)
    {
        $info = finfo_open(FILEINFO_MIME_TYPE);
        $mime =  finfo_file($info, $file);
        finfo_close($info);

        $fileExplode = explode('.', $file);
        $fileExtension = end($fileExplode);

        return array(
			'mime' => $mime, 
			'mediaType' => ($fileExtension === 'jpg') 
				? 'pic' 
				: ($fileExtension === 'mp4' ? 'video' : 'doc')
		);
    }

	/**
	 * 发送媒体
	 * 
	 * @param $post
	 * @param $post_url_header
	 * @param $user_id
	 * @param $media_id
	 * @return bool
	 *
	 * @create 2017-9-1
	 * @author deatil
	 */
    public function webwxsendmsgimg(
		$post, 
		$post_url_header, 
		$user_id, 
		$media_id
	) {
        $url = sprintf(
			$post_url_header . '/webwxsendmsgimg?fun=async&f=json&pass_ticket=%s', 
			$post->pass_ticket
		);
        $clientMsgId = (time() * 1000) . substr(uniqid(), 0,5);
        $data = array(
            "BaseRequest" => $post->BaseRequest,
            "Msg" => array(
                "Type" => 3,
                "MediaId" => $media_id,
                "FromUserName" => $post->User['UserName'],
                "ToUserName" => $user_id,
                "LocalID" => $clientMsgId,
                "ClientMsgId" => $clientMsgId
            )
        );
		
        $dic = $this->curlPost($url, $data);

        return $dic['BaseResponse']['Ret'] == 0;
    }

	/**
	 * 发送表情
	 * 
	 * @param $post
	 * @param $post_url_header
	 * @param $user_id
	 * @param $media_id
	 * @return bool
	 *
	 * @create 2017-9-1
	 * @author deatil
	 */
    public function webwxsendmsgemotion(
		$post, 
		$post_url_header, 
		$user_id, 
		$media_id
	) {
        $url = sprintf($post_url_header.'/webwxsendemoticon?fun=sys&f=json&pass_ticket=%s' , $this->pass_ticket);
        $clientMsgId = (time() * 1000) . substr(uniqid(), 0,5);
        $data = array(
            "BaseRequest" => $post->BaseRequest,
            "Msg" => array(
                "Type" => 47,
                "EmojiFlag" => 2,
                "MediaId" => $media_id,
                "FromUserName" => $post->User['UserName'],
                "ToUserName" => $user_id,
                "LocalID" => $clientMsgId,
                "ClientMsgId" => $clientMsgId
            )
        );
        $dic = $this->curlPost($url, $data);

        return $dic['BaseResponse']['Ret'] == 0;
    }

	/**
	 * 退出登录
	 * 
	 * @param $post
	 * @param $post_url_header
	 * @return bool
	 */
	public function wxloginout($post, $post_url_header)
	{
		$url = $post_url_header . '/webwxlogout?redirect=1&type=1&skey=' . urlencode($post->skey);
		$param = array(
			'sid' => $post->sid,
			'uin' => $post->uin,
		);
		$this->curlPost($url, $param);

		return true;
	}

	/**
	 * CURL请求
	 * 
	 * @create 2017-9-1
	 * @author deatil
	 */
	private function curlPost(
		$url, 
		$data = array(), 
		$is_gbk = true, 
		$timeout = 30, 
		$CA = false
	) {
		$cacert = getcwd() . '/cacert.pem'; //CA根证书

		$SSL = substr($url, 0, 8) == "https://" ? true : false;

		$header = array('ContentType: application/json; charset=UTF-8');

		$ch = curl_init();
		curl_setopt($ch, CURLOPT_HTTPHEADER, $header);
		curl_setopt($ch, CURLOPT_URL, $url);
		curl_setopt($ch, CURLOPT_TIMEOUT, $timeout);
		curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, $timeout - 2);
		if ($SSL && $CA) {
			curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);   // 只信任CA颁布的证书
			curl_setopt($ch, CURLOPT_CAINFO, $cacert); // CA根证书（用来验证的网站证书是否是CA颁布）
			curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2); // 检查证书中是否设置域名，并且是否与提供的主机名匹配
		} else if ($SSL && !$CA) {
			curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false); // 信任任何证书
			curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, true); // 检查证书中是否设置域名
		}
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
		curl_setopt($ch, CURLOPT_HTTPHEADER, array('Expect:')); //避免data数据过长问题
		if ($data) {
			if ($is_gbk) {
				$data = $this->json_encode($data);
			} else {
				$data = $this->json_encode($data);
			}
			curl_setopt($ch, CURLOPT_POST, true);
			curl_setopt($ch, CURLOPT_POSTFIELDS, $data);
		}
		curl_setopt($ch, CURLOPT_COOKIEFILE, $this->cookieJar);
		curl_setopt($ch, CURLOPT_COOKIEJAR, $this->cookieJar);

		//curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($data)); //data with URLEncode
		$ret = curl_exec($ch);
		curl_close($ch);
		return $ret;
	}

	/**
	 * 对微信文件上传的CURL封装
	 *
	 * @param string $url
	 * @param array $param
	 * @param boolean $post_file 是否文件上传
	 * @return string content
	 *
	 * @create 2017-9-1
	 * @author deatil
	 */
	private function weixinPost(
		$url,
		$param,
		$jsonfmt = true,
		$post_file = false
	) {
		$oCurl = curl_init();
		if (stripos($url, "https://") !== FALSE) {
			curl_setopt($oCurl, CURLOPT_SSL_VERIFYPEER, FALSE);
			curl_setopt($oCurl, CURLOPT_SSL_VERIFYHOST, false);
			curl_setopt($oCurl, CURLOPT_SSLVERSION, 1); //CURL_SSLVERSION_TLSv1
		}
        if (PHP_VERSION_ID >= 50500 && class_exists('CURLFile')) {
        	$is_curlFile = true;
        } else {
        	$is_curlFile = false;
        	if (defined('CURLOPT_SAFE_UPLOAD')) {
            	curl_setopt($oCurl, CURLOPT_SAFE_UPLOAD, false);
        	}
        }
		
		$filesHeader = '';
		if (is_string($param)) {
        	$strPOST = $param;
        } elseif ($post_file) {
			foreach ($param as $key => $val) {
				if (substr($val, 0, 1) == '@') {
					$files[$key] = realpath(substr($val, 1));
					unset($param[$key]);
				}
			}
			$params = $param;
			
			if ($files) {
				$body = $this->build_http_query_multi($params, $files);
				$filesHeader = "Content-Type: multipart/form-data; boundary=" . $this->boundary;
			} else {
				$body = http_build_query($params);
			}
			
			$strPOST = $body;
		} elseif (is_array($param) && $jsonfmt) {
			$strPOST = $this->json_encode($param);
		} else {
			$aPOST = array();
			foreach ($param as $key => $val) {
				$aPOST[] = $key."=".urlencode($val);
			}
			$strPOST = implode("&", $aPOST);
		}
		
		$user_agent = 'Mozilla/5.0 (Windows NT 6.1; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/52.0.2743.116 Safari/537.36';
        $header = array(
			'Accept:*/*',
			'Accept-Encoding:gzip, deflate, br',
			'Accept-Language:zh-CN,zh;q=0.8',
			'Cache-Control:no-cache',
			'Connection:keep-alive',
			'Content-Length:'.strlen($strPOST),
			$filesHeader,
			'Host:file.wx2.qq.com',
			'Origin:https://wx2.qq.com',
			'Pragma:no-cache',
			'Referer:https://wx2.qq.com/',
            'User-Agent: '.$user_agent,
        );
		
		curl_setopt($oCurl, CURLOPT_HTTPHEADER, $header);
		curl_setopt($oCurl, CURLOPT_URL, $url);
		curl_setopt($oCurl, CURLOPT_RETURNTRANSFER, 1);
		curl_setopt($oCurl, CURLOPT_POST, true);
		curl_setopt($oCurl, CURLOPT_POSTFIELDS, $strPOST);
		curl_setopt($oCurl, CURLOPT_COOKIEFILE, $this->cookieJar);
		curl_setopt($oCurl, CURLOPT_COOKIEJAR, $this->cookieJar);
		$sContent = curl_exec($oCurl);
		$aStatus = curl_getinfo($oCurl);
		
		curl_close($oCurl);
		if (intval($aStatus["http_code"]) == 200) {
			return $sContent;
		} else {
			return false;
		}
	}

	/**
	 * 格式化上传文件信息，封装上传信息包
	 * 
	 * @create 2017-9-1
	 * @author deatil
	 */
    private function build_http_query_multi($params, $files) 
	{
        if (!$params) {
			return '';
		}

        $this->boundary = $boundary = '----WebKitFormBoundary'.uniqid();
        $MPboundary = '--' . $boundary;
        $endMPboundary = "\r\n" . $MPboundary . "--\r\n";
        $multipartbody = '';

        foreach ($params as $key => $value) {
            $multipartbody .= $MPboundary . "\r\n";
            $multipartbody .= 'content-disposition: form-data; name="' . $key . "\"\r\n\r\n";
            $multipartbody .= $value . "\r\n";
        }
        foreach ($files as $key => $value) {
            if (!$value) {
				continue;
			}
            
            if (is_array($value)) {
                $url = $value['url'];
                if (isset($value['name'])) {
                    $filename = $value['name'];
                } else {
                    $parts = explode( '?', basename($value['url']));
                    $filename = $parts[0];
                }
                $field = isset($value['field']) ? $value['field'] : $key;
            } else {
                $url = $value;
                $parts = explode( '?', basename($url));
                $filename = $parts[0];
                $field = $key;
            }
            $content = file_get_contents($url);
			
			$mimeType = $this->mime_content_type($url);
        
            $multipartbody .= $MPboundary . "\r\n";
            $multipartbody .= 'Content-Disposition: form-data; name="' . $field . '"; filename="' . $filename . '"'. "\r\n";
            $multipartbody .= "Content-Type: " . $mimeType . "\r\n\r\n";
            $multipartbody .= $content. "\r\n";
        }

        $multipartbody .= $endMPboundary;
        return $multipartbody;
    }

	/**
	 * json
	 * 
	 * @create 2017-9-1
	 * @author deatil
	 */
    protected function json_encode($json)
	{
        return json_encode($json, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
    }
}

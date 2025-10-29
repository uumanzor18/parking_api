<?php

function notEmptyValue($value){
    return $value;
}


class ApiWrapper {

    var $apiKey;
    var $apiSecret;
    var $apiUrl;
    var $assoc;

    function __construct($apiKey, $apiSecret, $apiUrl, $assoc){
        $this->apiKey = $apiKey;
        $this->apiSecret = $apiSecret;
        if ($apiUrl[strlen($apiUrl)-1] !== '/') {
            $apiUrl .= '/' ;
        }
        $this->apiUrl = $apiUrl;
        $this->assoc = $assoc;
    }

    public function checkContactStatus($status){
        if ($status){
            if (!in_array($status,array(
                "SUSCRIBED","CONFIRMED","CANCELLED","INVITED",
            ))) throw new Exception("Status is not a valid status");
        }
    }

    public function checkInteger($value, $boolean=false){
        if (!$value) return;
        if (!is_numeric($value)) throw new Exception("Value $value is not numeric.");
        if ($boolean && $value!='1' && $value!='0') throw new Exception("Value $value is not 0 or 1.");
    }

    public function checkDate(&$value, $required=false){
        if (!$value && !$required) return;
        if (!is_numeric($value)) $value = strtotime($value);
        if (!$value) throw new Exception("Value $value is not a date.");
        $value = date("Y-m-d H:i:s",$value);
    }

    public function checkArray($value, $required=false){
        if (!is_array($value)) throw new Exception("$value is not an array");
    }


    public function getParamsString($params){
        if ($params) {
            if (!is_array($params) && !is_object($params)) throw new Exception('expected array or object in $params');
            ksort($params);
            $params = http_build_query(array_filter($params, "notEmptyValue"));
        }
        return $params;
    }

    public function getBodyString($body){
        if ($body) {
            if (!is_array($body) && !is_object($body)) throw new Exception('expected array or object in $body');
            $body = json_encode(array_filter($body,"notEmptyValue"));
        }
        return $body;
    }

    public function get($endpoint, $params=null){
        $url = $this->apiUrl.$endpoint;
        $params = $this->getParamsString($params);
        return $this->send($url,$params, 'GET', null);
    }

    public function post($endpoint, $params=null, $body=null){
        $url = $this->apiUrl.$endpoint;
        $params = $this->getParamsString($params);
        $body = $this->getBodyString($body);
        return $this->send($url,$params, 'POST', $body);
    }

    public function put($endpoint, $params=null, $body=null){
        $url = $this->apiUrl.$endpoint;
        $params = $this->getParamsString($params);
        $body = $this->getBodyString($body);
        return $this->send($url,$params, 'PUT', $body);
    }

    public function delete($endpoint, $params=null){
        $url = $this->apiUrl.$endpoint;
        $params = $this->getParamsString($params);
        return $this->send($url,$params, 'DELETE', null);
    }

    public function send($url, $params, $method, $body){
        if ($params) $url = $url."?".$params;
        $datetime = gmdate("D, d M Y H:i:s T");
        $authentication = $this->apiKey.$datetime.$params.$body;
        $hash = hash_hmac("sha1",$authentication, $this->apiSecret,true);
        $hash = base64_encode($hash);
        $headers = array(
            "Content-type: application/json",
            "Date: $datetime",
            "Authorization: IM $this->apiKey:$hash",
            "X-IM-ORIGIN: IM_SDK_PHP",
        );
        
		$data = $this->send_curl($url, $headers, $body);
        $json = json_decode($data,$this->assoc);
        $has_code = preg_match('/\ (\d+)\ /', $json->header, $response_code);
        if ($has_code) $response_code = $response_code[1];
        else $response_code = null;
        $has_status = preg_match('/\ ([^\ ]+)$/', $json->header, $status);
        if ($has_status) $status = $status[1];
        $data = array(
            'code' => $response_code+0,
            'status' => $status,
            'ok' => $status=="OK",
            'response_headers' => $json->header,
            'data' => $json->body,
        );
        return $this->assoc?$data:(object)$data;
    }
	
	public function send_curl($url, $headers, $body){
		//open connection
		$ch = curl_init();

		//set the url, number of POST vars, POST data
		curl_setopt($ch, CURLOPT_HEADER, 1);
		curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, true );
		curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false );
		curl_setopt($ch, CURLOPT_FOLLOWLOCATION, 1);
		curl_setopt($ch, CURLOPT_URL,$url);
		curl_setopt($ch, CURLOPT_POST,true);
		curl_setopt($ch, CURLOPT_POSTFIELDS,$body);
		
		//execute post
		$exec = curl_exec($ch);
		if ($exec === FALSE || $exec == '') {
			return curl_error($ch);
		}
		$header_size = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
		$resHeader = substr($exec, 0, $header_size);
		$resBody = substr($exec, $header_size);
		curl_close($ch);
		$response = array(
			"header" =>$resHeader,
			"body" => $resBody
		);
		return json_encode($response);
	}
}
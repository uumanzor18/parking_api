<?php

function transformaArray($input)
{
	$input = mapearObjeto($input);
	return implode(', ', array_map(
		function ($v, $k) {
			if (is_array($v)) {
				return $k . '=> [' . transformaArray($v) . ']';
			} else {
				return $k . '=' . $v;
			}
		},
		$input,
		array_keys($input)
	));
}

function mapearObjeto($objectName)
{
	if (!is_object($objectName) && !is_array($objectName)) {
		return $objectName;
	}

	return array_map('mapearObjeto', (array)$objectName);
}

function validarFechaExp($fecha)
{
	if (preg_match("/^(0[1-9]|1[0-2])\/[0-9]{4}$/", $fecha)) {
		return true;
	} else {
		return false;
	}
}

function validarTextoEmailUrl($secciones){

	$patronCorreo = '/[a-zA-Z0-9._%+-]+@[a-zA-Z0-9.-]+\.[a-zA-Z]{2,}/';
	$patronUrl = '/\b((http|https):\/\/[a-zA-Z0-9.-]+\.[a-zA-Z]{2,}(\/\S*)?)/i';
	//Detectar si el texto tiene un correo
	if (preg_match_all($patronCorreo, $secciones, $coincidencias)) {
		foreach ($coincidencias[0] as $texto) {
			$secciones = str_replace($texto, "<u><a style='font-size:13px;fontweight:normal;' href='mailto:".$texto."'>".$texto."</a></u>", $secciones);
		}
		
	}  

	//Detectar si el texto tiene una url 
	if (preg_match_all($patronUrl, $secciones, $coincidencias)) {
		foreach ($coincidencias[0] as $texto) {
			$secciones = str_replace($texto, "<u><a style='font-size:13px;font-weight:normal;' href='".$texto."' target='_blank' >".$texto."</a></u>", $secciones);
		}
		
	}

	return $secciones;
}

function isHex($entrada)
{
	$strlen = strlen($entrada);
	if ($strlen > 12) {
		return false;
	}
	$entrada = strtoupper($entrada);

	for ($i = 0; $i < $strlen; $i++) {
		if (!ctype_xdigit($entrada[$i])) {
			log_message("error", "no es hexadecimal " . $entrada[$i]);
			return false;
		}
	}
	return true;
}

function SmsMarketing($to, $msg, $from='CABLECOLOR', $mtid='', $date='') { 
	if(!empty($to) && is_numeric($to) && !empty($msg) && is_string($msg)) {
		$numero_destinatario = $to;
		$txt = $msg;
	}
	else {
		// Si no molan los POST, error
		return false;
	}

	$xml ='<?xml version="1.0" encoding="iso-8859-1"?>
		   <COMMAND><USER>cablecolor</USER> <PASS>cablecolor2019$
			</PASS><NUMERO>+'.$numero_destinatario.'
			</NUMERO><MESSAGE>'.$txt.'</MESSAGE>';
	$xml .= $from != '' ? '<REMITENTE>'.$from.'</REMITENTE>' : '<REMITENTE>CABLECOLOR</REMITENTE>';
	$xml .= $date != '' ? '<FECHA>'.$date.'</FECHA>' : '';
	$xml .= $mtid != '' ? '<MT_ID>'.strtoupper($mtid).'</MT_ID>' : '';
	$xml .= '</COMMAND>';

	$headers = [
		"Content-type: application/x-www-form-urlencoded"
	];

	$curl = curl_init();
	curl_setopt($curl,CURLOPT_URL, 'http://impactmobilehn.net/xmlapi.php');
	curl_setopt($curl, CURLOPT_POST, true);
	curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
	curl_setopt($curl, CURLOPT_HTTPHEADER, $headers);
	curl_setopt($curl,CURLOPT_POSTFIELDS, $xml);
	$result = curl_exec($curl);
	
	if (curl_errno($curl)) {
		log_message("error", "SmsMarketing/error: ".curl_error($curl));
		curl_close($curl);
		return ["estado" => "0"];
	}
	else{
		curl_close($curl);
		$data = simplexml_load_string($result);
		log_message("error", "SmsMarketing/result: ".json_encode($result));
		
		if(trim($data["RESULT"]["#"]["ESTATUS"][0]["#"]) == '200') {
			return ["estado" => "1"];
		}
		else {
			["estado" => "0"];	
		}
	}
}

function MoviTex($destinatario, $mensaje, $remitente) {
	$url = 'https://mms.movitext.com/graphql';

	$mutation = <<<MUTATION
	mutation send(\$sendSingularSmsInput: SmsSingularSendInput!) {
	  sendSingularSms(sendSingularSmsInput: \$sendSingularSmsInput) {
		status
		totalMessages
	  }
	}
	MUTATION;

	$datos = [
        'query' => $mutation,
        'variables' => [
            'sendSingularSmsInput' => [
                'message' => $remitente.": ".$mensaje,
                'recipient' => $destinatario,
                'sender' => $remitente
            ]
        ]
    ];

	array_walk_recursive($datos, function(&$item, $key) {
        $item = utf8_encode($item);
    });
    $json = json_encode($datos, JSON_UNESCAPED_UNICODE);

	$opciones = [
        CURLOPT_URL => $url,
        CURLOPT_SSL_VERIFYPEER => false,//este valor desabilita la solicitud de ssl lo pueden eliminar e produccion si asi es requerido.
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $json,
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
            'Content-Length: ' . strlen($json),
            'x-api-key: U2FsdGVkX18vi/RoDhNs1DML4pPHfcP0bVNOHnKc6DzdpHYqps0vequpwfeFZXEZCQyCWg19pnQqHqxy/KiD4g=='// hash utilizarla con cautela
        ]
    ];

	$curl = curl_init();

    // Establecer las opciones de la solicitud cURL
    curl_setopt_array($curl, $opciones);

    // Ejecutar la solicitud cURL
    $respuesta = curl_exec($curl);

    // Verificar si hubo errores en la solicitud cURL
    if ($respuesta === false) {
        $error = curl_error($curl);
        curl_close($curl);
        return "Error en la solicitud cURL: $error";
    }

    // Decodificar la respuesta JSON
    $datosRespuesta = json_decode($respuesta, true);

	if (curl_errno($curl)) {
		log_message("error", "MoviTex/error: ".curl_error($curl));
		curl_close($curl);
		return ["estado" => "0"];
	}
	else{
		curl_close($curl);
		//log_message("error", "MoviTex/result: ".print_r($datosRespuesta, true));
		
		if($datosRespuesta["data"]["sendSingularSms"]["status"] == "sent") {
			return ["estado" => "1"];
		}
		else {
			return ["estado" => "0"];	
		}
	}
}

function Tigo_sms($phone, $msg, $key, $secret){
	include APPPATH.'libraries/Smsapi.php';
	
	$api = new Smsapi($key, $secret, 'https://mensajeriacorporativa.tigobusiness.hn/api/rest', false);
	$response =	$api->messages()->sendToContact($phone,	$msg);
	$return = [];
	log_message("error", "Tigo_sms/result: ".json_encode($response));
	if ($response->status	== "OK" || $response->code == 200){
		return ["estado" => "1"];
	}else{
		$resultado = "Failed to send message with status code $response->code\n";
		return ["estado" => "0"];
	}
}

function getBodySV($dataBody){
	//parrafos
	$utf = "UTF-8";
	$windows = "windows-1252";
	$estado = $dataBody['estado'];
	if($estado != 'X'){
		//cid:imgcc
		$body = '<!doctype html>
					<html lang="es">
					<head>
					<meta charset="utf-8">
					<meta name="viewport" content="width=device-width, initial-scale=1.0">
					<title></title>
					<style>
						body {margin:0;padding:0;background-color:#f4f6f8;font-family:Arial,Helvetica,sans-serif;}
						table {border-collapse:collapse;}
						@media only screen and (max-width:600px){
						.container{width:100% !important;}
						.stack-column{display:block !important;width:100% !important;max-width:100% !important;}
						.icon-section td{text-align:center !important;display:block;width:100% !important;}
						}
						.btn {
						display:inline-block;
						padding:8px 16px;
						background:#6b7280;
						color:#ffffff !important;
						border-radius:15px;
						font-size:13px;
						text-decoration:none;
						font-weight:600;
						text-align:center;
						min-width:110px;
						}
					</style>
					</head>
					<body style="background-color:#f4f6f8;margin:0;padding:0;">
					<table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="background-color:#f4f6f8;">
						<tr>
						<td align="center">
							<table role="presentation" width="640" class="container" cellspacing="0" cellpadding="0" border="0" style="max-width:640px;background:#ffffff;border-radius:8px;overflow:hidden;">
							<!-- Imagen de cabecera -->
							<tr>
								<td style="padding:0;">
								<img src="cid:imgcc" alt="Gracias por tu preferencia" width="640" style="width:100%;max-width:640px;display:block;border:0;">
								</td>
							</tr>

							<!-- Saludo -->
							<tr>
								<td style="padding:24px;">
								<p style="font-size:16px;color:#1e293b;margin:0 0 12px;">Estimado(a) <strong>'.$dataBody["nombresCL"].'</strong>,</p>
								<p style="font-size:15px;color:#334155;margin:0 0 12px;">'.$dataBody['textoAviso'].'</p>
								</td>
							</tr>

							<!-- Montos -->
							<tr>
								<td style="padding:0 24px 24px;">
								<table width="100%" cellpadding="0" cellspacing="0" border="0" style="border:1px solid #e2e8f0;border-radius:6px;">
									<tr>
									<td style="padding:12px 16px;font-size:15px;color:#475569;">Saldo vencido</td>
									<td align="right" style="padding:12px 16px;font-size:15px;color:#dc2626;font-weight:700;">US$. '.$dataBody["saldoVencido"].'</td>
									</tr>
									<tr style="background:#f9fafb;">
									<td style="padding:12px 16px;font-size:15px;color:#475569;">Saldo mes actual</td>
									<td align="right" style="padding:12px 16px;font-size:15px;color:#475569;font-weight:700;">US$. '.$dataBody["montoFactura"].'</td>
									</tr>
									<tr>
									<td style="padding:12px 16px;font-size:15px;color:#0f172a;font-weight:700;border-top:1px solid #e2e8f0;">Total a pagar</td>
									<td align="right" style="padding:12px 16px;font-size:16px;color:#0b63d6;font-weight:800;border-top:1px solid #e2e8f0;">US$. '.$dataBody["saldoVencido"].'</td>
									</tr>
								</table>
								</td>
							</tr>

							<!-- Boton pago rapido -->
							<tr>
								<td style="padding:0 24px 24px;text-align:center;">
								<a href="https://pagorapido.cablecolor.com.sv" target="_blank" style="display:inline-block;padding:12px 24px;background-color:#4B0082;color:#ffffff;text-decoration:none;font-weight:600;border-radius:20px;font-size:15px;">Pago Rapido</a>
								</td>
							</tr>

							<!-- SECCION DE ICONOS -->

							<tr>
								<td style="padding:24px 24px 40px;">
								<table width="100%" cellpadding="0" cellspacing="0" border="0" class="icon-section">
								<tr>
								<td align="center" width="33.33%" style="vertical-align:top;padding:12px;">
								<img src="cid:imgcc1" alt="Agencias Cable Color" width="120" height="120" style="display:block;margin:0 auto 12px;border:0;">
								<h3 style="margin:0;font-size:15px;color:#0f172a;font-weight:700;">Agencias de Cable Color</h3>
								<p style="margin:4px 0 16px;font-size:14px;color:#475569;">Conoce la ubicación de nuestras Agencias</p>
								<a href="https://pagorapido.cablecolor.com.sv" class="btn">Ir al Sitio</a>
								</td>


								<td align="center" width="33.33%" style="vertical-align:top;padding:12px;">
								<img src="cid:imgcc2" alt="Punto Xpress" width="120" height="120" style="display:block;margin:0 auto 12px;border:0;">
								<h3 style="margin:0;font-size:15px;color:#0f172a;font-weight:700;">Punto Xpress</h3>
								<p style="margin:4px 0 16px;font-size:14px;color:#475569;">Conoce todos los Punto Xpress del país</p>
								<a href="https://pagorapido.cablecolor.com.sv" class="btn">Ir al Sitio</a>
								</td>


								<td align="center" width="33.33%" style="vertical-align:top;padding:12px;">
								<img src="cid:imgcc3" alt="Pago automático" width="120" height="120" style="display:block;margin:0 auto 12px;border:0;">
								<h3 style="margin:0;font-size:15px;color:#0f172a;font-weight:700;">Pago automático</h3>
								<p style="margin:4px 0 16px;font-size:14px;color:#475569;"><!-- Afilíese y disfrute de pagos sin preocupaciones --></p>
								<a href="https://pagorapido.cablecolor.com.sv" class="btn">Afiliarse</a>
								</td>
								</tr>
								</table>
								</td>
							</tr>

							<!-- Mensaje final -->
							<tr>
								<td style="padding:0 24px 32px;">
								<p align="center" style="font-size:15px;color:#334155;line-height:1.5;">Si tiene alguna consulta, no dude en llamarnos al <strong>2537-7400</strong> o escribanos a nuestro número de WhatsApp <strong>6987-5089</strong>.</p>
								<p align="center" style="font-size:15px;color:#334155;line-height:1.5;">Pague su factura antes del día <strong>15</strong> y disfrute del mejor servicio de internet y cable sin interrupciones.</p>
								<p align="center" style="font-size:15px;color:#6b7280;line-height:1.5;"><strong>Cable Color El Internet más rápido de El Salvador</strong></p>
								</td>
							</tr>
							</table>
						</td>
						</tr>
					</table>
					</body>
					</html>';
		
		$body .= "\n\n";
	}else{
		$body = "Estimado cliente, adjunto encontrara el documento anulado y el archivo JSON";
	}
	return $body;
}

function cargarPdf($documento, $tipo){
	$contenido = "";
	if ($tipo == 1) {
		$ruta = APPPATH . 'facturaspdfES/'.$documento.'.pdf';
	}else{
		$tuta = APPPATH . 'notasCreditopdfES/'.$documento.'.pdf';
	}
	if (file_exists($ruta)) {
		$contenido = file_get_contents($ruta);
		$contenido = base64_encode($contenido);
	}

	return $contenido;
}

function saveDocumentoFirmadoSV($documento, $firmado){
	if (isset($documento["documento"])) {
		$tipo_documento = "AN";
		$numero_control = $documento["documento"]["numeroControl"];
	}else{
		$tipo_documento = $documento["identificacion"]["tipoDte"];
    	$numero_control = $documento["identificacion"]["numeroControl"];
	}

    $directorio = APPPATH . 'facturasJsonES/';
    $archivo = $directorio . "{$numero_control}_{$tipo_documento}.txt";

	$resultado = @file_put_contents($archivo, $firmado, LOCK_EX);
	
	if ($resultado !== false) {
		return true;
	}else{
		log_message("error", "utils_helper.php->saveDocumentoFirmadoSV Error: No se pudo almacenar el documento:" . $archivo);
		return false;
	}
}

function enviarFirmadoGT($claveFirmador, $base64XML, $codigo, $usuarioFirmador, $esAnulacion, $urlFirmador){

	$headers = array(
		"Content-Type: application/json"
	);

	$dataPost = array(
		"llave"=>$claveFirmador,
		"archivo"=>$base64XML,
		"codigo"=>$codigo,
		"alias"=>$usuarioFirmador,
		"es_anulacion"=>$esAnulacion
	);
	$dataIn = json_encode($dataPost);

	$curl = curl_init();
	$curlFirmador = curl_init();
	curl_setopt($curlFirmador, CURLOPT_URL, $urlFirmador);
	curl_setopt($curlFirmador, CURLOPT_RETURNTRANSFER, true);
	curl_setopt($curlFirmador, CURLOPT_SSL_VERIFYPEER, false);
	curl_setopt($curlFirmador, CURLOPT_SSL_VERIFYHOST, false);
	curl_setopt($curlFirmador, CURLOPT_MAXREDIRS, 10);
	curl_setopt($curlFirmador, CURLOPT_TIMEOUT, 60);
	curl_setopt($curlFirmador, CURLOPT_HTTP_VERSION, CURL_HTTP_VERSION_1_1);
	curl_setopt($curlFirmador, CURLOPT_CUSTOMREQUEST, "POST");
	curl_setopt($curlFirmador, CURLOPT_POSTFIELDS, $dataIn);
	curl_setopt($curlFirmador, CURLOPT_HTTPHEADER, $headers);

	$result = curl_exec($curlFirmador);
	$result = json_decode($result);

	if (curl_errno($curlFirmador)) {
		log_message("error", "Error firmadorGT CURL: " . " " .curl_error($curlFirmador)." accionesFEL.php");
		//curl_close($curlFirmador);
	}

	curl_close($curlFirmador);

	$status = $result->resultado;
	if ($status == true) {
		$codigo = "COD01";
		$mensaje = $result->descripcion;
		$base64Firmado = $result->archivo;
		//$this->writeLogInfile("factura firmada: ".$this->base64Firmado." ", "", "accionesFEL.php");
	}else{
		$codigo = "COD03";
		$mensaje = $result->descripcion;
		$base64Firmado = $result->archivo;
		log_message("error", "firmadorGT, Error=".print_r($result, true));
	}

	return [$codigo, $mensaje, $base64Firmado];

}

function enviarCertificadorFEL($nit_emisor, $correo_copia, $xml_dte, $identificador, $claveCertificador,$usuarioCertificador, $urlCertificador){

	$headers = array(
		"Content-Type: application/json",
		"identificador: ".$identificador."",
		"llave: ".$claveCertificador."",
		"usuario: ".$usuarioCertificador
	);
			
	$dataPost = array(
		"nit_emisor"=>$nit_emisor,
		"correo_copia"=> $correo_copia,
		"xml_dte"=>$xml_dte
	);
	$dataIn = json_encode($dataPost);

	$curlFEL = curl_init();
	curl_setopt($curlFEL, CURLOPT_URL, $urlCertificador);
	curl_setopt($curlFEL, CURLOPT_RETURNTRANSFER, true);
	curl_setopt($curlFEL, CURLOPT_SSL_VERIFYPEER, false);
	curl_setopt($curlFEL, CURLOPT_SSL_VERIFYHOST, false);
	curl_setopt($curlFEL, CURLOPT_MAXREDIRS, 10);
	curl_setopt($curlFEL, CURLOPT_TIMEOUT, 60);
	curl_setopt($curlFEL, CURLOPT_HTTP_VERSION, CURL_HTTP_VERSION_1_1);
	curl_setopt($curlFEL, CURLOPT_CUSTOMREQUEST, "POST");
	curl_setopt($curlFEL, CURLOPT_POSTFIELDS, $dataIn);
	curl_setopt($curlFEL, CURLOPT_HTTPHEADER, $headers);

	$result = curl_exec($curlFEL);
	$result = json_decode($result);

	if (curl_errno($curlFEL)) {
		log_message("error", "Error certificadorFEL CURL: " . " " .curl_error($curlFEL));
		//curl_close($curlFEL);
	}

	curl_close($curlFEL);
	//log_message("error", "datos certificador=".print_r($result, true));
	return $result;

}
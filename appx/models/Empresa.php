<?php
	class Empresa extends CI_Model{

		function __construct(){
			$this->load->database();
		}

		function getHost($factura){
			$this->db->select('EF.HOSTMAIL_ENVIOFACTURAFISCAL, EF.EMAIL_ENVIOFACTURAFISCAL, EF.CLAVE_EMAIL_ENVIOFACTURAFISCAL, CL.CLIENTE, F.NUMERACIONALT, E.NOMBRELEGAL');
            $this->db->from('CEPHEUS.FACTURA F');
            $this->db->join('EMPRESA E', 'F.EMPRESA = E.EMPRESA');
            $this->db->join('EMPRESAFACTURACION EF', 'F.EMPRESAFACTURACION = EF.EMPRESAFACTURACION');
            $this->db->join('CLIENTE CL', 'F.CLIENTE = CL.CLIENTE');
            $this->db->where('F.ESTADO', 'C');
            $this->db->where('F.NUMERACIONALT IS NOT NULL');
            $this->db->where('E.PAIS', 'HN');
            $this->db->where('F.FACTURA', $factura);
			$result = $this->db->get();

			if (!$result) {
				$error = $this->db->error();
				return  ["estado"=>false, "mensaje"=>$error['message']];
			}
            if ($result->num_rows() > 0) {
                return  ["estado"=>true, "credenciales"=>$result->row()];
            }else{
                return  ["estado"=>false, "mensaje"=>"empresa no cuenta con informacion de host"];
            }
		}
	}

 ?>
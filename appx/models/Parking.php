<?php
defined('BASEPATH') OR exit('No direct script access allowed');

class Parking extends CI_Model
{
    private $vehicles_table = 'vehicles';
    private $sessions_table = 'parking_sessions';
    private $users_table    = 'users';
    private $vehicle_types_table    ='vehicle_types';

    public function __construct()
    {
        parent::__construct();
        $this->load->database();
    }

    public function registerEntry(int $guard_id, string $plate, int $vehicle_type_id){
        $plate = strtoupper(trim($plate));

        $this->db->trans_start();

        // 1) Buscar/crear vehículo
        $veh = $this->db->select('id, plate, vehicle_type_id')
            ->from($this->vehicles_table)
            ->where('plate', $plate)
            ->limit(1)
            ->get()
            ->row();

        if (!$veh) {
            // Insertar vehículo
            $this->db->insert($this->vehicles_table, [
                'plate'           => $plate,
                'vehicle_type_id' => $vehicle_type_id
            ]);

            // Manejo de error de duplicado
            if ($this->db->error()['code'] === 1062) {
                // Ya lo insertó otro proceso; volver a consultar
                $veh = $this->db->select('id, plate, vehicle_type_id')
                    ->from($this->vehicles_table)
                    ->where('plate', $plate)
                    ->limit(1)
                    ->get()
                    ->row();
            } else {
                $veh = (object)[
                    'id'              => $this->db->insert_id(),
                    'plate'           => $plate,
                    'vehicle_type_id' => $vehicle_type_id
                ];
            }
        }

        // 2) Verificar que no haya sesión abierta para este vehículo
        $open = $this->db->select('id, entry_time')
            ->from($this->sessions_table)
            ->where('vehicle_id', $veh->id)
            ->where('exit_time IS NULL', null, false)
            ->limit(1)
            ->get()
            ->row();

        if ($open) {
            $this->db->trans_complete();
            return [
                'status'     => 'open_exists',
                'session_id' => (int)$open->id,
                'entry_time' => $open->entry_time
            ];
        }

        // 3) Abrir sesión de estacionamiento
        $now = date('Y-m-d H:i:s');

        $this->db->insert($this->sessions_table, [
            'vehicle_id' => $veh->id,
            'entry_time' => $now,
            'created_by' => $guard_id
        ]);

        $session_id = (int)$this->db->insert_id();

        $this->db->trans_complete();

        if ($this->db->trans_status() === false) {
            return [
                'status' => 'error',
                'error'  => $this->db->error()['message'] ?? 'DB transaction failed'
            ];
        }

        return [
            'status'      => 'ok',
            'session_id'  => $session_id,
            'vehicle_id'  => (int)$veh->id,
            'entry_time'  => $now
        ];
    }

    public function registerExit($closed_by, $plate){
        $exit_time = date('Y-m-d H:i:s');
        // 1) Traer vehículo + tipo + tarifa
        $veh = $this->db->select('v.id, v.plate, v.vehicle_type_id, t.hourly_rate')
            ->from("$this->vehicles_table AS v")
            ->join("$this->vehicle_types_table AS t", 't.id = v.vehicle_type_id', 'inner')
            ->where('v.plate', strtoupper($plate))
            ->limit(1)
            ->get()
            ->row();

        if (!$veh) {
            return ['status' => 'not_found_vehicle'];
        }

        // 2) Buscar sesión abierta
        $open = $this->db->select('id, entry_time')
            ->from($this->sessions_table)
            ->where('vehicle_id', $veh->id)
            ->where('exit_time IS NULL', null, false)
            ->order_by('id', 'desc')
            ->limit(1)
            ->get()
            ->row();

        if (!$open) {
            return ['status' => 'no_open_session'];
        }

        // 3) Calcular horas facturables según regla de 30 minutos (mínimo 1)
        $entry_ts = strtotime($open->entry_time);
        $exit_ts  = strtotime($exit_time);
        if ($exit_ts === false || $exit_ts <= $entry_ts) {
            return ['status' => 'error', 'error' => 'exit_time inválido o anterior a entry_time'];
        }

        $total_minutes = (int)ceil(($exit_ts - $entry_ts) / 60); // minutos totales (redondeo hacia arriba a 1 min)
        $whole_hours   = intdiv($total_minutes, 60);
        $rem_minutes   = $total_minutes % 60;

        // Regla: fracción >= 30 agrega 1 hora, fracción < 30 no agrega
        $hours_billed  = $whole_hours + ($rem_minutes >= 30 ? 1 : 0);

        // Mínimo 1 hora
        if ($hours_billed < 1) {
            $hours_billed = 1;
        }

        // 4) Tarifa por tipo (moto = 0)
        $rate   = (float)$veh->hourly_rate;      // 15.00, 0.00, 5.00
        $amount = round($rate * $hours_billed, 2);

        // 5) Actualizar sesión
        $this->db->trans_start();

        $this->db->where('id', $open->id)
                 ->update($this->sessions_table, [
                     'exit_time'    => $exit_time,
                     'hours_billed' => $hours_billed,
                     'amount'       => $amount,
                     'closed_by'    => $closed_by
                 ]);

        $this->db->trans_complete();

        if (!$this->db->trans_status()) {
            return ['status' => 'error', 'error' => $this->db->error()['message'] ?? 'DB fail'];
        }

        return [
            'status'         => 'ok',
            'session_id'     => (int)$open->id,
            'vehicle_id'     => (int)$veh->id,
            'vehicle_type_id'=> (int)$veh->vehicle_type_id,
            'entry_time'     => $open->entry_time,
            'exit_time'      => $exit_time,
            'hours_billed'   => $hours_billed,
            'amount'         => $amount,
            'rate'           => $rate
        ];
    }
}
<?php

class BatchJobController
{
    private $db;

    public function __construct()
    {
        $this->db = Flight::db();
    }

    // Obtener todos los batch jobs pendientes o fallidos
    public function get_all()
    {
        $query = $this->db->prepare("SELECT * FROM batch_jobs WHERE status IN ('pending') ORDER BY priority DESC, created_at ASC");
        $query->execute();
        $jobs = $query->fetchAll(PDO::FETCH_ASSOC);
        Flight::json(['response' => [
            'message' => 'OK',
            'jobs' => $jobs
        ]]);
    }

    // Función interna para crear o sumar un valor numérico a un batch job
    public function create_or_update_job($code, $value)
    {
        // Asegurarse de que $value es un número entero
        $int_value = is_numeric($value) ? intval($value) : 0;

        $query = $this->db->prepare("SELECT id, load_amount FROM batch_jobs WHERE code = ? AND status IN ('pending')");
        $query->execute([$code]);
        $existing = $query->fetch(PDO::FETCH_ASSOC);
        if ($existing) {
            $existing_load_amount = is_numeric($existing['load_amount']) ? intval($existing['load_amount']) : 0;
            $new_load_amount = $existing_load_amount + $int_value;
            $update = $this->db->prepare("UPDATE batch_jobs SET load_amount = ?, status = 'pending', completed_at = NULL WHERE id = ?");
            $update->execute([$new_load_amount, $existing['id']]);
            return [
                'message' => 'Actualizado',
                'id' => $existing['id']
            ];
        } else {
            $new_load_amount = $int_value;
            $insert = $this->db->prepare("INSERT INTO batch_jobs (load_amount, code, priority) VALUES (?, ?, ?)");
            $insert->execute([$new_load_amount, $code, 0]);
            return [
                'message' => 'Creado',
                'id' => $this->db->lastInsertId()
            ];
        }
    }

    // Crear o actualizar un batch job por code (content es un valor numérico)
    public function upsert()
    {
        $data = Flight::request()->data;
        $code = $data['code'] ?? null;
        $content = $data['content'] ?? null;
        if (!$code || !isset($content)) {
            Flight::halt(400, json_encode(['response' => ['message' => 'Faltan parámetros code o content']]));
        }
        $result = $this->create_or_update_job($code, $content);
        Flight::json(['response' => $result]);
    }

    // Cancelar (eliminar lógico) un batch job
    public function cancel($id)
    {
        $query = $this->db->prepare("UPDATE batch_jobs SET status = 'cancelled', completed_at = NOW() WHERE id = ?");
        $query->execute([$id]);
        if ($query->rowCount() > 0) {
            Flight::json(['response' => ['message' => 'Eliminado', 'id' => $id]]);
        } else {
            Flight::halt(404, json_encode(['response' => ['message' => 'No encontrado', 'id' => $id]]));
        }
    }

    // Actualizar un batch job (por ejemplo, marcar como completado o fallido)
    public function update_status($id)
    {
        $data = Flight::request()->data;
        $status = $data['status'] ?? null;
        $priority = $data['priority'] ?? null;
        $fields = [];
        $params = [];
        if ($status !== null) {
            if (!in_array($status, ['pending', 'running', 'completed', 'failed', 'cancelled'])) {
                Flight::halt(400, json_encode(['response' => ['message' => 'Estado inválido', 'id' => $id]]));
            }
            $fields[] = 'status = ?';
            $params[] = $status;
            if (in_array($status, ['completed', 'cancelled'])) {
                $fields[] = 'completed_at = NOW()';
            }
        }
        if ($priority !== null) {
            if (!is_numeric($priority)) {
                Flight::halt(400, json_encode(['response' => ['message' => 'Priority inválido', 'id' => $id]]));
            }
            $fields[] = 'priority = ?';
            $params[] = $priority;
        }
        if (empty($fields)) {
            Flight::halt(400, json_encode(['response' => ['message' => 'No hay campos para actualizar', 'id' => $id]]));
        }
        $setClause = implode(', ', $fields);
        $params[] = $id;
        $query = $this->db->prepare("UPDATE batch_jobs SET $setClause WHERE id = ?");
        $query->execute($params);
        if ($query->rowCount() > 0) {
            Flight::json(['response' => ['message' => 'Actualizado', 'id' => $id, 'status' => $status, 'priority' => $priority]]);
        } else {
            Flight::halt(404, json_encode(['response' => ['message' => 'No encontrado', 'id' => $id]]));
        }
    }
} 
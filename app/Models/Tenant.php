<?php
// app/Models/Tenant.php
namespace App\Models;

class Tenant
{
    public static function findById($id)
    {
        return db()->fetch('SELECT * FROM tenants WHERE id = ?', [$id]);
    }

    public static function all($keyword = '')
    {
        $sql = 'SELECT * FROM tenants';
        $params = [];
        if ($keyword !== '') {
            $sql .= ' WHERE name LIKE ? OR id_number LIKE ?';
            $params = ["%$keyword%", "%$keyword%"];
        }
        $sql .= ' ORDER BY id DESC';
        return db()->fetchAll($sql, $params);
    }

    public static function create($data)
    {
        return db()->insert('tenants', $data);
    }

    public static function update($id, $data)
    {
        return db()->update('tenants', $data, ['id' => $id]);
    }

    public static function delete($id)
    {
        return db()->delete('tenants', ['id' => $id]);
    }
}

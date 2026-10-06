<?php

/**
 * Конфигурация базы данных
 * Скопируйте в database.php:
 *
 *   cp database.example.php database.php
 */

require_once __DIR__ . '/server_config.php';

class Database
{
    private $connection;
    private static $instance = null;

    private function __construct()
    {
        try {
            $this->connection = new mysqli(DB_HOST, DB_USERNAME, DB_PASSWORD, DB_NAME);

            if ($this->connection->connect_error) {
                throw new Exception("Ошибка подключения к базе данных: " . $this->connection->connect_error);
            }

            $this->connection->set_charset(DB_CHARSET);
        } catch (Exception $e) {
            die("Ошибка подключения к базе данных: " . $e->getMessage());
        }
    }

    public static function getInstance()
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    public function getConnection()
    {
        return $this->connection;
    }

    public function query($sql)
    {
        return $this->connection->query($sql);
    }

    public function prepare($sql)
    {
        return $this->connection->prepare($sql);
    }

    public function getLastInsertId()
    {
        return $this->connection->insert_id;
    }

    public function getAffectedRows()
    {
        return $this->connection->affected_rows;
    }

    public function escape($string)
    {
        return $this->connection->real_escape_string($string);
    }

    public function begin_transaction()
    {
        return $this->connection->begin_transaction();
    }

    public function commit()
    {
        return $this->connection->commit();
    }

    public function rollback()
    {
        return $this->connection->rollback();
    }

    public function close()
    {
        if ($this->connection) {
            $this->connection->close();
        }
    }

    public function __destruct()
    {
        $this->close();
    }
}

function getDB()
{
    return Database::getInstance();
}

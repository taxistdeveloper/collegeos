<?php

/**
 * Конфигурация базы данных
 * Использует универсальную настройку для автоматического определения параметров сервера
 */

// Подключаем универсальную конфигурацию
require_once __DIR__ . '/server_config.php';

/**
 * Класс для работы с базой данных
 */
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

            // Устанавливаем кодировку
            $this->connection->set_charset(DB_CHARSET);
        } catch (Exception $e) {
            die("Ошибка подключения к базе данных: " . $e->getMessage());
        }
    }

    /**
     * Получить экземпляр класса (Singleton)
     */
    public static function getInstance()
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Получить соединение с базой данных
     */
    public function getConnection()
    {
        return $this->connection;
    }

    /**
     * Выполнить запрос
     */
    public function query($sql)
    {
        return $this->connection->query($sql);
    }

    /**
     * Подготовить запрос
     */
    public function prepare($sql)
    {
        return $this->connection->prepare($sql);
    }

    /**
     * Получить ID последней вставленной записи
     */
    public function getLastInsertId()
    {
        return $this->connection->insert_id;
    }

    /**
     * Получить количество затронутых строк
     */
    public function getAffectedRows()
    {
        return $this->connection->affected_rows;
    }

    /**
     * Экранировать строку для безопасности
     */
    public function escape($string)
    {
        return $this->connection->real_escape_string($string);
    }

    /**
     * Начать транзакцию
     */
    public function begin_transaction()
    {
        return $this->connection->begin_transaction();
    }

    /**
     * Подтвердить транзакцию
     */
    public function commit()
    {
        return $this->connection->commit();
    }

    /**
     * Откатить транзакцию
     */
    public function rollback()
    {
        return $this->connection->rollback();
    }

    /**
     * Закрыть соединение
     */
    public function close()
    {
        if ($this->connection) {
            $this->connection->close();
        }
    }

    /**
     * Деструктор
     */
    public function __destruct()
    {
        $this->close();
    }
}

// Функция для получения экземпляра базы данных
function getDB()
{
    return Database::getInstance();
}

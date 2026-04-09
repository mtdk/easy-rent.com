<?php
/**
 * 收租管理系统 - HTTP异常类
 * 
 * 用于表示HTTP相关的异常
 */

namespace App\Core;

class HttpException extends \Exception
{
    /**
     * @var int HTTP状态码
     */
    private $statusCode;
    
    /**
     * @var array 响应头
     */
    private $headers;
    
    /**
     * 构造函数
     * 
     * @param int $statusCode HTTP状态码
     * @param string $message 异常消息
     * @param array $headers 响应头
     * @param \Throwable|null $previous 前一个异常
     */
    public function __construct(int $statusCode, string $message = '', array $headers = [], ?\Throwable $previous = null)
    {
        $this->statusCode = $statusCode;
        $this->headers = $headers;
        
        parent::__construct($message, $statusCode, $previous);
    }
    
    /**
     * 获取HTTP状态码
     * 
     * @return int HTTP状态码
     */
    public function getStatusCode(): int
    {
        return $this->statusCode;
    }
    
    /**
     * 获取响应头
     * 
     * @return array 响应头
     */
    public function getHeaders(): array
    {
        return $this->headers;
    }
    
    /**
     * 创建400 Bad Request异常
     * 
     * @param string $message 消息
     * @param array $headers 响应头
     * @return self
     */
    public static function badRequest(string $message = 'Bad Request', array $headers = []): self
    {
        return new self(400, $message, $headers);
    }
    
    /**
     * 创建401 Unauthorized异常
     * 
     * @param string $message 消息
     * @param array $headers 响应头
     * @return self
     */
    public static function unauthorized(string $message = 'Unauthorized', array $headers = []): self
    {
        return new self(401, $message, $headers);
    }
    
    /**
     * 创建403 Forbidden异常
     * 
     * @param string $message 消息
     * @param array $headers 响应头
     * @return self
     */
    public static function forbidden(string $message = 'Forbidden', array $headers = []): self
    {
        return new self(403, $message, $headers);
    }
    
    /**
     * 创建404 Not Found异常
     * 
     * @param string $message 消息
     * @param array $headers 响应头
     * @return self
     */
    public static function notFound(string $message = 'Not Found', array $headers = []): self
    {
        return new self(404, $message, $headers);
    }
    
    /**
     * 创建405 Method Not Allowed异常
     * 
     * @param string $message 消息
     * @param array $headers 响应头
     * @return self
     */
    public static function methodNotAllowed(string $message = 'Method Not Allowed', array $headers = []): self
    {
        return new self(405, $message, $headers);
    }
    
    /**
     * 创建409 Conflict异常
     * 
     * @param string $message 消息
     * @param array $headers 响应头
     * @return self
     */
    public static function conflict(string $message = 'Conflict', array $headers = []): self
    {
        return new self(409, $message, $headers);
    }
    
    /**
     * 创建422 Unprocessable Entity异常
     * 
     * @param string $message 消息
     * @param array $headers 响应头
     * @return self
     */
    public static function unprocessableEntity(string $message = 'Unprocessable Entity', array $headers = []): self
    {
        return new self(422, $message, $headers);
    }
    
    /**
     * 创建429 Too Many Requests异常
     * 
     * @param string $message 消息
     * @param array $headers 响应头
     * @return self
     */
    public static function tooManyRequests(string $message = 'Too Many Requests', array $headers = []): self
    {
        return new self(429, $message, $headers);
    }
    
    /**
     * 创建500 Internal Server Error异常
     * 
     * @param string $message 消息
     * @param array $headers 响应头
     * @return self
     */
    public static function internalServerError(string $message = 'Internal Server Error', array $headers = []): self
    {
        return new self(500, $message, $headers);
    }
    
    /**
     * 创建503 Service Unavailable异常
     * 
     * @param string $message 消息
     * @param array $headers 响应头
     * @return self
     */
    public static function serviceUnavailable(string $message = 'Service Unavailable', array $headers = []): self
    {
        return new self(503, $message, $headers);
    }
}
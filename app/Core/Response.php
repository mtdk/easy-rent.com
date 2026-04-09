<?php
/**
 * 收租管理系统 - HTTP响应类
 * 
 * 负责HTTP响应的创建和发送
 */

namespace App\Core;

class Response
{
    /**
     * @var mixed 响应内容
     */
    private $content;
    
    /**
     * @var int HTTP状态码
     */
    private $statusCode;
    
    /**
     * @var array HTTP头
     */
    private $headers;
    
    /**
     * @var string HTTP协议版本
     */
    private $protocolVersion = '1.1';
    
    /**
     * @var array 状态码描述
     */
    private static $statusTexts = [
        100 => 'Continue',
        101 => 'Switching Protocols',
        102 => 'Processing',
        200 => 'OK',
        201 => 'Created',
        202 => 'Accepted',
        203 => 'Non-Authoritative Information',
        204 => 'No Content',
        205 => 'Reset Content',
        206 => 'Partial Content',
        207 => 'Multi-Status',
        208 => 'Already Reported',
        226 => 'IM Used',
        300 => 'Multiple Choices',
        301 => 'Moved Permanently',
        302 => 'Found',
        303 => 'See Other',
        304 => 'Not Modified',
        305 => 'Use Proxy',
        307 => 'Temporary Redirect',
        308 => 'Permanent Redirect',
        400 => 'Bad Request',
        401 => 'Unauthorized',
        402 => 'Payment Required',
        403 => 'Forbidden',
        404 => 'Not Found',
        405 => 'Method Not Allowed',
        406 => 'Not Acceptable',
        407 => 'Proxy Authentication Required',
        408 => 'Request Timeout',
        409 => 'Conflict',
        410 => 'Gone',
        411 => 'Length Required',
        412 => 'Precondition Failed',
        413 => 'Payload Too Large',
        414 => 'URI Too Long',
        415 => 'Unsupported Media Type',
        416 => 'Range Not Satisfiable',
        417 => 'Expectation Failed',
        418 => 'I\'m a teapot',
        421 => 'Misdirected Request',
        422 => 'Unprocessable Entity',
        423 => 'Locked',
        424 => 'Failed Dependency',
        425 => 'Too Early',
        426 => 'Upgrade Required',
        428 => 'Precondition Required',
        429 => 'Too Many Requests',
        431 => 'Request Header Fields Too Large',
        451 => 'Unavailable For Legal Reasons',
        500 => 'Internal Server Error',
        501 => 'Not Implemented',
        502 => 'Bad Gateway',
        503 => 'Service Unavailable',
        504 => 'Gateway Timeout',
        505 => 'HTTP Version Not Supported',
        506 => 'Variant Also Negotiates',
        507 => 'Insufficient Storage',
        508 => 'Loop Detected',
        510 => 'Not Extended',
        511 => 'Network Authentication Required',
    ];
    
    /**
     * 构造函数
     * 
     * @param mixed $content 响应内容
     * @param int $statusCode HTTP状态码
     * @param array $headers HTTP头
     */
    public function __construct($content = '', int $statusCode = 200, array $headers = [])
    {
        $this->content = $content;
        $this->statusCode = $statusCode;
        $this->headers = array_merge([
            'Content-Type' => 'text/html; charset=UTF-8',
            'X-Powered-By' => 'EasyRent/1.0',
        ], $headers);
    }
    
    /**
     * 发送响应
     * 
     * @return void
     */
    public function send(): void
    {
        $this->sendHeaders();
        $this->sendContent();
        
        if (function_exists('fastcgi_finish_request')) {
            fastcgi_finish_request();
        } elseif (!in_array(PHP_SAPI, ['cli', 'phpdbg'], true)) {
            static::closeOutputBuffers(0, true);
        }
    }
    
    /**
     * 发送HTTP头
     * 
     * @return void
     */
    public function sendHeaders(): void
    {
        // 如果头已发送，直接返回
        if (headers_sent()) {
            return;
        }
        
        // 设置状态码
        header(sprintf('HTTP/%s %s %s', $this->protocolVersion, $this->statusCode, $this->getStatusText()), true, $this->statusCode);
        
        // 设置其他头
        foreach ($this->headers as $name => $value) {
            header($name . ': ' . $value, false, $this->statusCode);
        }
    }
    
    /**
     * 发送响应内容
     * 
     * @return void
     */
    public function sendContent(): void
    {
        echo $this->content;
    }
    
    /**
     * 获取状态码描述
     * 
     * @return string 状态码描述
     */
    public function getStatusText(): string
    {
        return self::$statusTexts[$this->statusCode] ?? 'Unknown Status';
    }
    
    /**
     * 设置响应内容
     * 
     * @param mixed $content 响应内容
     * @return self
     */
    public function setContent($content): self
    {
        $this->content = $content;
        return $this;
    }
    
    /**
     * 获取响应内容
     * 
     * @return mixed 响应内容
     */
    public function getContent()
    {
        return $this->content;
    }
    
    /**
     * 设置状态码
     * 
     * @param int $statusCode HTTP状态码
     * @return self
     */
    public function setStatusCode(int $statusCode): self
    {
        $this->statusCode = $statusCode;
        return $this;
    }
    
    /**
     * 获取状态码
     * 
     * @return int HTTP状态码
     */
    public function getStatusCode(): int
    {
        return $this->statusCode;
    }
    
    /**
     * 设置HTTP头
     * 
     * @param string $name 头名称
     * @param string $value 头值
     * @return self
     */
    public function setHeader(string $name, string $value): self
    {
        $this->headers[$name] = $value;
        return $this;
    }
    
    /**
     * 获取HTTP头
     * 
     * @param string|null $name 头名称（null表示获取所有头）
     * @return mixed 头值或所有头
     */
    public function getHeader(?string $name = null)
    {
        if ($name === null) {
            return $this->headers;
        }
        
        return $this->headers[$name] ?? null;
    }
    
    /**
     * 检查是否有HTTP头
     * 
     * @param string $name 头名称
     * @return bool 是否有该头
     */
    public function hasHeader(string $name): bool
    {
        return isset($this->headers[$name]);
    }
    
    /**
     * 移除HTTP头
     * 
     * @param string $name 头名称
     * @return self
     */
    public function removeHeader(string $name): self
    {
        unset($this->headers[$name]);
        return $this;
    }
    
    /**
     * 设置内容类型
     * 
     * @param string $contentType 内容类型
     * @param string $charset 字符集
     * @return self
     */
    public function setContentType(string $contentType, string $charset = 'UTF-8'): self
    {
        $this->setHeader('Content-Type', $contentType . '; charset=' . $charset);
        return $this;
    }
    
    /**
     * 设置JSON内容类型
     * 
     * @return self
     */
    public function setJsonContentType(): self
    {
        return $this->setContentType('application/json');
    }
    
    /**
     * 设置HTML内容类型
     * 
     * @return self
     */
    public function setHtmlContentType(): self
    {
        return $this->setContentType('text/html');
    }
    
    /**
     * 设置纯文本内容类型
     * 
     * @return self
     */
    public function setTextContentType(): self
    {
        return $this->setContentType('text/plain');
    }
    
    /**
     * 设置XML内容类型
     * 
     * @return self
     */
    public function setXmlContentType(): self
    {
        return $this->setContentType('application/xml');
    }
    
    /**
     * 设置缓存控制
     * 
     * @param string $cacheControl 缓存控制指令
     * @return self
     */
    public function setCacheControl(string $cacheControl): self
    {
        $this->setHeader('Cache-Control', $cacheControl);
        return $this;
    }
    
    /**
     * 设置无缓存
     * 
     * @return self
     */
    public function setNoCache(): self
    {
        return $this->setCacheControl('no-cache, no-store, must-revalidate')
                    ->setHeader('Pragma', 'no-cache')
                    ->setHeader('Expires', '0');
    }
    
    /**
     * 设置重定向
     * 
     * @param string $url 重定向URL
     * @param int $statusCode 状态码
     * @return self
     */
    public function setRedirect(string $url, int $statusCode = 302): self
    {
        $this->setStatusCode($statusCode);
        $this->setHeader('Location', $url);
        return $this;
    }
    
    /**
     * 设置下载文件
     * 
     * @param string $filename 文件名
     * @param string $content 文件内容
     * @param string $contentType 内容类型
     * @return self
     */
    public function setDownload(string $filename, string $content, string $contentType = 'application/octet-stream'): self
    {
        $this->setContentType($contentType);
        $this->setHeader('Content-Disposition', 'attachment; filename="' . $filename . '"');
        $this->setHeader('Content-Length', (string) strlen($content));
        $this->setContent($content);
        return $this;
    }
    
    /**
     * 创建JSON响应
     * 
     * @param mixed $data JSON数据
     * @param int $statusCode HTTP状态码
     * @param array $headers HTTP头
     * @return self
     */
    public static function json($data, int $statusCode = 200, array $headers = []): self
    {
        $response = new self(json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT), $statusCode, $headers);
        $response->setJsonContentType();
        return $response;
    }
    
    /**
     * 创建HTML响应
     * 
     * @param string $html HTML内容
     * @param int $statusCode HTTP状态码
     * @param array $headers HTTP头
     * @return self
     */
    public static function html(string $html, int $statusCode = 200, array $headers = []): self
    {
        $response = new self($html, $statusCode, $headers);
        $response->setHtmlContentType();
        return $response;
    }
    
    /**
     * 创建纯文本响应
     * 
     * @param string $text 文本内容
     * @param int $statusCode HTTP状态码
     * @param array $headers HTTP头
     * @return self
     */
    public static function text(string $text, int $statusCode = 200, array $headers = []): self
    {
        $response = new self($text, $statusCode, $headers);
        $response->setTextContentType();
        return $response;
    }
    
    /**
     * 创建重定向响应
     * 
     * @param string $url 重定向URL
     * @param int $statusCode 状态码
     * @param array $headers HTTP头
     * @return self
     */
    public static function redirect(string $url, int $statusCode = 302, array $headers = []): self
    {
        $response = new self('', $statusCode, $headers);
        $response->setRedirect($url, $statusCode);
        return $response;
    }
    
    /**
     * 创建错误响应
     * 
     * @param string $message 错误消息
     * @param int $statusCode HTTP状态码
     * @param array $headers HTTP头
     * @return self
     */
    public static function error(string $message, int $statusCode = 500, array $headers = []): self
    {
        $html = '<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>错误 - ' . $statusCode . '</title>
    <style>
        body { font-family: Arial, sans-serif; padding: 20px; background: #f8f9fa; }
        .error-container { max-width: 600px; margin: 50px auto; text-align: center; }
        .error-code { font-size: 72px; color: #dc3545; margin: 0; }
        .error-message { font-size: 24px; color: #6c757d; margin: 20px 0; }
        .error-details { background: white; padding: 20px; border-radius: 5px; box-shadow: 0 2px 10px rgba(0,0,0,0.1); }
    </style>
</head>
<body>
    <div class="error-container">
        <h1 class="error-code">' . $statusCode . '</h1>
        <h2 class="error-message">' . htmlspecialchars($message) . '</h2>
        <div class="error-details">
            <p>抱歉，处理您的请求时发生了错误。</p>
            <p><a href="/">返回首页</a></p>
        </div>
    </div>
</body>
</html>';
        
        return self::html($html, $statusCode, $headers);
    }
    
    /**
     * 创建未找到响应
     * 
     * @param string $message 消息
     * @param array $headers HTTP头
     * @return self
     */
    public static function notFound(string $message = '页面未找到', array $headers = []): self
    {
        return self::error($message, 404, $headers);
    }
    
    /**
     * 创建未授权响应
     * 
     * @param string $message 消息
     * @param array $headers HTTP头
     * @return self
     */
    public static function unauthorized(string $message = '未授权访问', array $headers = []): self
    {
        return self::error($message, 401, $headers);
    }
    
    /**
     * 创建禁止访问响应
     * 
     * @param string $message 消息
     * @param array $headers HTTP头
     * @return self
     */
    public static function forbidden(string $message = '禁止访问', array $headers = []): self
    {
        return self::error($message, 403, $headers);
    }
    
    /**
     * 创建视图响应
     * 
     * @param string $view 视图名称
     * @param array $data 视图数据
     * @param int $statusCode HTTP状态码
     * @param array $headers HTTP头
     * @return self
     */
    public static function view(string $view, array $data = [], int $statusCode = 200, array $headers = []): self
    {
        $content = view($view, $data);
        return self::html($content, $statusCode, $headers);
    }
    
    /**
     * 关闭输出缓冲区
     * 
     * @param int $targetLevel 目标级别
     * @param bool $flush 是否刷新
     * @return void
     */
    public static function closeOutputBuffers(int $targetLevel, bool $flush): void
    {
        $status = ob_get_status(true);
        $level = count($status);
        
        while ($level-- > $targetLevel) {
            if ($flush) {
                ob_end_flush();
            } else {
                ob_end_clean();
            }
        }
    }
    
    /**
     * 魔术方法：将响应转换为字符串
     * 
     * @return string 响应字符串表示
     */
    public function __toString(): string
    {
        return (string) $this->content;
    }
}
<?php  // src/Middleware/JsonBodyParser.php 
namespace App\Middleware;

use Psr\Http\Message\ResponseInterface; 
use Psr\Http\Message\ServerRequestInterface; 
use Psr\Http\Server\MiddlewareInterface; 
use Psr\Http\Server\RequestHandlerInterface; 
  
final class JsonBodyParser implements MiddlewareInterface 
{ 
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface 
    { 
        if (stripos($request->getHeaderLine('Content-Type'), 'application/json') === 0) { 
            $raw     = (string)$request->getBody(); 
            $decoded = $raw === '' ? [] : json_decode($raw, true); 
            if (!is_array($decoded)) $decoded = []; 
            $request = $request->withParsedBody($decoded); 
        } 
        return $handler->handle($request); 
    } 
} 
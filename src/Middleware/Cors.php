<?php  // src/Middleware/Cors.php 
namespace App\Middleware; 
  
use Psr\Http\Message\ResponseInterface; 
use Psr\Http\Message\ServerRequestInterface; 
use Psr\Http\Server\MiddlewareInterface; 
use Psr\Http\Server\RequestHandlerInterface; 
  
final class Cors implements MiddlewareInterface 
{ 
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface 
    { 
        if ($request->getMethod() === 'OPTIONS') { 
            $response = new \Slim\Psr7\Response(204); 
            return $this->withCors($response); 
        } 
        return $this->withCors($handler->handle($request)); 
    } 
    private function withCors(ResponseInterface $r): ResponseInterface { 
        return $r 
            ->withHeader('Access-Control-Allow-Origin',  '*') 
            ->withHeader('Access-Control-Allow-Headers', 'Content-Type, Authorization') 
            ->withHeader('Access-Control-Allow-Methods', 'GET, POST, PUT, DELETE, OPTIONS'); 
    } 
} 
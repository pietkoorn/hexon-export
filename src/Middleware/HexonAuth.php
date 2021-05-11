<?php

namespace RoyScheepens\HexonExport\Middleware;

use Closure;
use Illuminate\Http\Request;

class HexonAuth
{
    protected bool $enabled;
    protected string $username;
    protected string $password;

    public function __construct() {
        $this->enabled = config('hexon-export.auth.enabled', false);
        $this->username = config('hexon-export.auth.username');
        $this->password = config('hexon-export.auth.password');
    }

    /**
     * Handle an incoming request.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  \Closure  $next
     * @return mixed
     */
    public function handle($request, Closure $next)
    {
        if(app()->environment('production') && $enabled) {
            if($request->getUser() != $username && $request->getPassword() != $password) {
                $headers = array('WWW-Authenticate' => 'Basic');
                return response('Unauthorized', 401, $headers);
            }
        }

        return $next($request);
    }
}
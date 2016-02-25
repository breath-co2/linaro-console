<?php

$checkLogin = function()
{

};

return function($request, $response) use ($checkLogin)
{
    $response->header('Server', 'MYS');
    $response->header('Content-Type', 'application/json');
    if (isset($request->header['origin']))
    {
        $response->header('Access-Control-Allow-Origin', $request->header['origin']);
        $response->header('Access-Control-Allow-Headers', 'X-Server-Auth');
    }
    if (strtolower($request->server['request_method']) === 'options')
    {
        # ajax中option请求
        $response->end('{"status":"optionsok"}');
        return;
    }

    $data = [];
    $uri = trim($request->server['request_uri'], ' /');

    if ($uri === 'login')
    {
        // 登录
        $allow = false;

        if (!$request->post['username'])
        {
            $data['message'] = '缺少用户名';
        }
        elseif (!$request->post['password'])
        {
            $data['status']  = 'errorpass';
            $data['message'] = '缺少密码';
        }
        else
        {
            $users = @parse_ini_file(__DIR__.'/../user.ini');
            if ($users)
            {
                $username  = strtolower($request->post['username']);
                $password  = $request->post['password'];
                $autologin = $request->post['autologin'] ? true : false;

                if (!isset($users[$username]) || $users[$username] != sha1($password . '_abcdef123456'))
                {
                    $data['message'] = '用户名或密码错误';
                }
                else
                {
                    # 登录成功，生成授权
                    $auth = sha1($username . $password .'___'. microtime(1));
                    $authFile = __DIR__ .'/../.authoried';
                    if (is_file($authFile))
                    {
                        $allAuth = unserialize(file_get_contents($authFile));
                        if ($allAuth)
                        {
                            # 清理过期的auth
                            $time = time();
                            foreach($allAuth as $k => $v)
                            {
                                if ($time > $v['exp_time'])
                                {
                                    unset($allAuth[$k]);
                                    $isClean = true;
                                }
                            }
                        }
                        else
                        {
                            $allAuth = [];
                        }
                    }
                    else
                    {
                        $allAuth = [];
                    }

                    $allAuth[$auth] = [
                        'time'     => time(),
                        'exp_time' => $autologin ? time() + 86400 * 60 : time() + 86400,
                    ];

                    # 更新文件
                    file_put_contents($authFile, serialize($allAuth));

                    $data['status'] = 'ok';
                    $data['auth']   = $auth;
                    $allow          = 'login';
                }
            }
            else
            {
                $data['message'] = '系统没有可用的帐号，无法登录，如果你是管理员，请在服务器server命令目录中创建user.ini并设定相关内容';
            }
        }
    }
    elseif ($uri === 'logout')
    {
        if ($request->get['auth'])
        {
            $authFile = __DIR__ .'/../.authoried';
            $allAuth = unserialize(file_get_contents($authFile));
            if ($allAuth)
            {
                unset($allAuth[$request->get['auth']]);
                file_put_contents($authFile, serialize($allAuth));        
            }

            $data['status'] = 'ok';
            $allow = 'logout';
        }
    }
    elseif (isset($request->header['x-server-auth']))
    {
        # 验证AUTH
        $auth = $request->header['x-server-auth'];
        $authFile = __DIR__ .'/../.authoried';
        $allAuth = unserialize(file_get_contents($authFile));
        if (false === $allAuth)$allAuth = [];

        if (!isset($allAuth[$auth]))
        {
            $data['status'] = 'errorauth';
            $allow = 'errorauth';
        }
        elseif (time() > $allAuth[$auth]['exp_time'])
        {
            # 过期了
            $data['status'] = 'exp_auth';
            $allow = 'errorauth';
        }
        else
        {
            $allow = true;
        }
    }
    else
    {
        $allow = false;
    }

 
    if (true === $allow)
    {
        $ENV = 'export LANG="zh_CN.UTF-8";export PATH="/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin:/usr/games:/usr/local/games";';

        switch ($uri)
        {
            case 'status':
                exec('ps -e | grep vod_httpserver', $rs);
                if (count($rs))
                {
                    $data['status']['xunlei'] = 'ok';
                }
                else
                {
                    $data['status']['xunlei'] = 'no';
                }

                $rs = [];
                exec('ls /mnt/data/', $rs);
                if (count($rs))
                {
                    $data['status']['afp'] = 'ok';
                }
                else
                {
                    $data['status']['afp'] = 'no';
                }
                break;

            case 'xunlei/stop':
                exec($ENV.'cd /xunlei;/xunlei/portal -s', $rs);
                $data['status'] = 'ok';
                break;

            case 'xunlei/restart':
            case 'xunlei/start':
                exec($ENV.'cd /xunlei && /xunlei/portal', $rs);
                if (in_array('finished.', $rs))
                {
                    $data['status'] = 'ok';
                }
                else
                {
                    $data['status'] = 'error';
                }
                break;

            case 'afp/start':
                $afpkey = $request->post['afpkey'];

                $data['status'] = 'error';
                if ($afpkey && preg_match('#^[a-z0-9_\-]+$#i', $afpkey))
                {
                    shell_exec($ENV.'mount_afp afp://down:000@10.0.1.1/Data/ /mnt/data > /dev/null &');
                    sleep(2);
                    $rs = shell_exec($ENV.'mount_afp afp://down:'. $afpkey .'@10.0.1.1/Data/ /mnt/data > /tmp/afp_rs &');

                    $t = 0;
                    while (true) 
                    {
                        $t++;
                        sleep(1);
                        exec('ps -e | grep mount_afp', $rs);
                        if (!$rs)
                        {
                            # 没有进程了
                            break;
                        }
                        elseif ($t > 10)
                        {
                            # 10 秒还没有连上，强制退出
                            foreach($rs as $item)
                            {
                                list($pid) = explode(' ', trim($item));
                                exec('kill -9 '. $pid);
                            }

                            break;
                        }
                    }

                    if (is_file('/tmp/afp_rs'))
                    {
                        $rs = file_get_contents('/tmp/afp_rs');
                        unlink('/tmp/afp_rs');
                    }
                    else
                    {
                        $rs = '';
                    }

                    if (false !== strpos($rs, 'succeeded.'))
                    {
                        $data['status'] = 'ok';
                    }
                    elseif (false !== strpos($rs, 'Authentication failed'))
                    {
                        $data['status'] = 'autherror';
                    }
                    elseif (false !== strpos($rs, 'Volume Data is already mounted'))
                    {
                        # 已连接
                        $data['status'] = 'ok';
                    }
                    else
                    {
                        $data['error'] = $rs;
                    }
                }
                break;

            case 'afp/stop':
                exec('umount /mnt/data/', $rs);
                $data['status'] = 'ok';
                break;

            case 'reboot':
                global $serv;
                $data['status'] = 'ok';
                swoole_timer_after(1000, function() use ($serv){
                    exec('reboot');
                    $serv->shutdown();
                });
                break;

            case 'server/status':
                $data['status'] = 'ok';
                break;

            case 'api/stop':
                global $serv;
                $data['status'] = 'ok';
                swoole_timer_after(500, function() use ($serv){
                    $serv->shutdown();
                });
                break;

            case 'api/restart':
            case 'api/reload':
                global $serv;
                $data['status'] = 'ok';
                swoole_timer_after(500, function() use ($serv){
                    $serv->reload();
                });
                break;

            case 'baidu/compare':
                $rootDir = '/mnt/baidu';
             
                # 先检查下是否挂载共享盘
                exec("ls {$rootDir}", $rs);
                if (!count($rs))
                {
                    //$data['status'] = 'nomnt';
                    //break;
                }

                $rs = shell_exec($ENV.'/usr/bin/python3 -x /root/bypy/bypy.py compare / '. $rootDir);

                $data['status'] = 'ok';
                $data['list'] = [
                    'same'   => [],
                    'diff'   => [],
                    'local'  => [],
                    'remote' => [],
                ];

                $map = [
                    'Same files'      => 'same',
                    'Different files' => 'diff',
                    'Local only'      => 'local',
                    'Remote only'     => 'remote',
                    'Same'            => 'same',
                    'Different'       => 'diff',
                ];

                $type = null;
                $isStat = false;
                foreach (explode("\n", $rs) as $item)
                {
                    if ($isStat)
                    {
                        if (preg_match('#^(.*): (\d+)#', $item, $m))
                        {
                            $data['stat'][$map[$m[1]]] = (int)$m[2];
                        }
                    }
                    elseif ($item === 'Statistics:')
                    {
                        $isStat = true;
                    }
                    elseif (!$isStat)
                    {
                        if (preg_match('#==== (.*) ===#', $item, $m))
                        {
                            $type = $map[$m[1]];
                        }
                        elseif (preg_match('#^(F|D) \- (.*)$#', $item, $m))
                        {
                            $data['list'][$type][] = [
                                'name' => $m[2],
                                'type' => strtolower($m[1]),
                                'icon' => $m[1] == 'F' ? 'file-text-o' : 'folder-o',
                            ];
                        }
                    }
                }

                break;
            case 'baidu/action':
                $rootDir = '/mnt/baidu';

                # 先检查下是否挂载共享盘
                exec("ls {$rootDir}/", $rs);
                if (!count($rs))
                {
                    //$data['status'] = 'nomnt';
                    //break;
                }

                $action = $request->post['action'];
                $file   = '/'. trim(str_replace(['\\', '../'], ['/', ''], $request->post['file']), '/');
                $lfile  = escapeshellarg($rootDir . $file);
                $file   = escapeshellarg($file);

                switch ($action) 
                {
                    case 'download':
                        if ($request->post['type'] === 'd')
                        {
                            # 文件夹
                            mkdir($rootDir . $request->post['file'], 0766, true);
                        }
                        else
                        {
                            # 文件
                            $rs = shell_exec($exec = $ENV.'/usr/bin/python3 -x /root/bypy/bypy.py download '. $file .' '. $lfile);
                        }
                        break;
                        
                    case 'upload':
                        if ($request->post['type'] === 'd')
                        {
                            $rs = shell_exec($ENV.'/usr/bin/python3 -x /root/bypy/bypy.py mkdir '. $file);
                        }
                        else
                        {
                            $rs = shell_exec($ENV.'/usr/bin/python3 -x /root/bypy/bypy.py upload '. $lfile .' '. $file);
                        }
                        break;
                        
                    case 'delete':
                        $rs = shell_exec($ENV.'/usr/bin/python3 -x /root/bypy/bypy.py delete '. $file);
                        break;

                    case 'dellocal':
                        $rs = shell_exec('rm -rf '. $lfile);
                        break;

                    case 'delall':
                        shell_exec('rm -rf '. $lfile);
                        $rs = shell_exec($ENV.'/usr/bin/python3 -x /root/bypy/bypy.py delete '. $file);
                        break;

                    case 'syncdown':
                    case 'syncup':
                        # 同步下载、同步上传
                        $rs = shell_exec($ENV.'/usr/bin/python3 -x /root/bypy/bypy.py '. $action. ' / '. $rootDir);
                    
                    default:
                        break;
                }

                $data['status'] = 'ok';
                $data['rs']     = $rs;
                $data['exec']   = $exec;
                break;

            default:
                $data['message'] = '未知方法';
        }
    }
    else if (false === $allow)
    {
        $data['status'] = 'nologin';
    }

    $response->end(json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n");
};
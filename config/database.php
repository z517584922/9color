<?php

return [
    // 数据库调试模式
    'debug'       => true,
    // 数据库类型
    'type'        => 'mysql',
    // 服务器地址 - 连接到独立的database-server
    'hostname'    => getenv('DB_HOST') ?: '38.180.150.127',
    // 数据库名
    'database'    => getenv('DB_NAME') ?: '6ui',
    // 用户名
    'username'    => getenv('DB_USER') ?: '9color_user',
    // 密码
    'password'    => getenv('DB_PASS') ?: '9color@2025#DB',
    // 编码
    'charset'     => 'utf8mb4',
    // 端口
    'hostport'    => getenv('DB_PORT') ?: '3306',
    // 主从
    'deploy'      => 0,
    // 分离
    'rw_separate' => false,
];
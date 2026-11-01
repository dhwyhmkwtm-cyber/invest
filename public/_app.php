<?php
// アプリ本体 (app/) の場所。公開ディレクトリの外に置いたパスを指定する。
// 例: ロリポップで ~/mailer/app に置いた場合
//   return '/home/users/0/LOGIN/mailer/app';
return getenv('MAILER_APP_DIR') ?: dirname(__DIR__) . '/app';

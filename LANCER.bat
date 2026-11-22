@echo off
cd /d "%~dp0"
where php >nul 2>nul || (echo Installez PHP 8.4.1 ou plus et ajoutez-le au PATH. & pause & exit /b 1)
php -r "exit(PHP_VERSION_ID >= 80401 ? 0 : 1);"
if errorlevel 1 (echo Ce projet demande PHP 8.4.1 ou plus. & php -v & pause & exit /b 1)
where composer >nul 2>nul || (echo Installez Composer et ajoutez-le au PATH. & pause & exit /b 1)
call composer install --no-interaction
if errorlevel 1 (pause & exit /b 1)
php bin/console cache:clear
if errorlevel 1 (pause & exit /b 1)
echo Ouvrez http://localhost:3000
php -d upload_max_filesize=16G -d post_max_size=17G -d max_input_time=300 -S localhost:3000 -t public public/router.php
pause

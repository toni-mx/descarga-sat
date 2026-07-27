@echo off
echo ======================================================
echo Iniciando servidor local PHP para SAT Sync...
echo El panel estara disponible en: http://localhost:8000
echo ======================================================
echo.
start http://localhost:8000
php -S localhost:8000 -t public/
pause

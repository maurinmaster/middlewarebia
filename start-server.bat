@echo off
title Middleware Bia - Nuvemshop e eGestor
echo ========================================================
echo Iniciando Middleware Bia (Integracao eGestor e Nuvemshop)
echo ========================================================

REM Procura o executavel do PHP no PATH ou no XAMPP
set PHP_BIN=php
where php >nul 2>nul
if %errorlevel% neq 0 (
    if exist "C:\xampp\php\php.exe" (
        set PHP_BIN=C:\xampp\php\php.exe
    ) else (
        echo [ERRO] PHP nao encontrado no sistema nem em C:\xampp\php\php.exe
        pause
        exit /b 1
    )
)

echo PHP encontrado em: %PHP_BIN%
echo Servidor rodando em: http://localhost:8080
echo.
echo Para acessar o painel, abra no navegador:
echo   http://localhost:8080
echo.
echo Pressione Ctrl+C para encerrar.
echo.

"%PHP_BIN%" -S 0.0.0.0:8080 -t public
pause

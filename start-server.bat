@echo off
REM Avvia il server PHP built-in per Rugby Tagger e apre il browser sul menu.
REM router.php applica le stesse protezioni di .htaccess (il server integrato non lo legge).
REM Il server resta in ascolto solo su localhost: chiudere la finestra (o Ctrl+C) per fermarlo.

setlocal
set PORT=8000

REM Lavora sempre dalla cartella del progetto, anche se il .bat e' lanciato da altrove.
cd /d "%~dp0"

where php >nul 2>nul
if errorlevel 1 (
    echo [ERRORE] php non trovato nel PATH. Aggiungi la cartella di PHP ^(es. C:\xampp\php^) al PATH.
    pause
    exit /b 1
)

REM Il server PHP built-in non si ferma da solo se la porta e' occupata: meglio dirlo subito.
netstat -ano | findstr /r /c:":%PORT% .*LISTENING" >nul
if not errorlevel 1 (
    echo [ERRORE] La porta %PORT% e' gia' in uso. Chiudi l'altro server o cambia PORT in questo file.
    pause
    exit /b 1
)

echo Rugby Tagger su http://localhost:%PORT%  ^(Ctrl+C per fermare^)
start "" "http://localhost:%PORT%/"
php -S localhost:%PORT% router.php

endlocal

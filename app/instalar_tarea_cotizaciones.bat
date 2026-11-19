@echo off
rem Abre PowerShell como administrador (Windows pide confirmacion) y crea la tarea que actualiza las cotizaciones.
powershell -NoProfile -Command "Start-Process powershell -Verb RunAs -ArgumentList '-NoProfile -ExecutionPolicy Bypass -NoExit -File %~dp0instalar_tarea_cotizaciones.ps1'"

# Skill: Generación Guiada por Pruebas (TDD)

## Objetivo
Escribir tests antes de implementar la lógica funcional.

## Ejecución
1. Cuando el usuario pida una nueva funcionalidad, el agente PRIMERO escribirá el test en Pest o PHPUnit.
2. El test debe fallar inicialmente (Rojo).
3. El agente escribirá el código mínimo en el controlador/servicio para que el test pase (Verde).
4. El agente revisará si el código cumple con `01-laravel-core.md` y lo refactorizará si es necesario.
5. Considerar siempre casos "Happy Path", casos de validación fallida y errores de base de datos.
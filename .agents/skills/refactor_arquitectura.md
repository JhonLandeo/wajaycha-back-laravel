# Skill: Refactorización a Clean Architecture

## Objetivo
Analizar código existente (ej. "Fat Controllers") y separarlo en capas.

## Ejecución
1. Extraer la validación del Request a un `FormRequest`.
2. Extraer la recolección de datos/preparación a un DTO.
3. Mover la lógica de base de datos (Eloquent) a un `Repository`.
4. Mover la lógica de negocio central a un `Action` o `UseCase`.
5. Asegurar que el controlador solo orqueste (recibe Request -> llama Service -> devuelve Response).
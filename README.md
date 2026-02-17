# VISOR KINO

Centro de contenido para distribuidores. Busca por SKU, consulta fichas de producto, y descarga fotos/videos.

## Requisitos

- PHP 8.1+
- MySQL 8.0
- Composer
- Docker (para Railway)

## Desarrollo local

```bash
# 1. Instalar dependencias
composer install

# 2. Copiar variables de entorno
cp .env.example .env
# Editar .env con tus credenciales de MySQL local

# 3. Ejecutar migraciones
php migrate.php

# 4. Iniciar servidor
php -S localhost:8080
```

## Deploy en Railway

1. Crear proyecto en [Railway](https://railway.app)
2. Agregar plugin **MySQL**
3. Conectar tu repositorio GitHub
4. Configurar variables de entorno (ver `.env.example`)
5. Deploy automático con cada `git push`

## Importar catálogo

1. Ir a `/admin/login`
2. Ir a **Importar Excel**
3. Subir archivo `.xlsx` o `.csv` con columnas: `sku, name, category, gender, movement, price_suggested, status, description`

## Estructura

```
├── index.php           # Router principal
├── config/             # Configuración (DB, app, storage)
├── src/controllers/    # Lógica de negocio
├── src/helpers.php     # Funciones reutilizables
├── templates/          # Vistas HTML (client + admin)
├── migrations/         # SQL de creación de tablas
├── assets/             # CSS, JS estáticos
└── Dockerfile          # Para deploy en Railway
```

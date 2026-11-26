-- v2 módulo 3 (2026-11-26, regla 2 del dueño): el cliente edita su pedido solo mientras está «Recibido». edit_token = llave secreta que queda en su dispositivo.
ALTER TABLE client_orders ADD COLUMN edit_token CHAR(32) NULL DEFAULT NULL;

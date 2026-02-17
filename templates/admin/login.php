<!DOCTYPE html>
<html lang="es">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Login · KINO</title>
    <link rel="stylesheet" href="/assets/css/style.css?v=<?= APP_VERSION ?>">
</head>

<body>
    <div style="min-height:100vh; display:flex; align-items:center; justify-content:center;">
        <div class="container-sm fade-in" style="max-width:400px;">
            <div style="text-align:center; margin-bottom:2rem;">
                <div class="logo" style="justify-content:center; font-size:2rem; margin-bottom:0.5rem;">
                    <span class="logo-icon" style="width:48px;height:48px;font-size:1.5rem;">K</span>
                    KINO
                </div>
                <p style="color:var(--color-text-muted); font-size:0.9rem;">Panel de Administración</p>
            </div>

            <?php if (isset($_SESSION['login_error'])): ?>
                <div class="alert alert-error">
                    <?= e($_SESSION['login_error']) ?>
                </div>
                <?php unset($_SESSION['login_error']); ?>
            <?php endif; ?>

            <form method="POST" action="/admin/login"
                style="background:var(--color-surface); border:1px solid var(--color-border); border-radius:var(--radius-lg); padding:2rem;">
                <div class="form-group">
                    <label for="username">Usuario</label>
                    <input type="text" id="username" name="username" class="form-input" required autofocus>
                </div>
                <div class="form-group">
                    <label for="password">Contraseña</label>
                    <input type="password" id="password" name="password" class="form-input" required>
                </div>
                <button type="submit" class="btn btn-primary btn-block" style="margin-top:0.5rem;">
                    Entrar
                </button>
            </form>

            <p style="text-align:center; margin-top:1.5rem;">
                <a href="/" style="color:var(--color-text-dim); font-size:0.8rem;">← Volver al portal</a>
            </p>
        </div>
    </div>
</body>

</html>
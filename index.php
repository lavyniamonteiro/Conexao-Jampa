<?php


session_start();


define('DB_HOST',   'localhost');
define('DB_NAME',   'conexao_jampa');
define('DB_USER',   'root');        // seu usuário do MySQL
define('DB_PASS',   '');            // sua senha do MySQL
define('DB_CHARSET','utf8mb4');


function getConnection(): PDO {
    static $pdo = null;

    if ($pdo === null) {
        $dsn = sprintf(
            'mysql:host=%s;dbname=%s;charset=%s',
            DB_HOST, DB_NAME, DB_CHARSET
        );

        $options = [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ];

        $pdo = new PDO($dsn, DB_USER, DB_PASS, $options);
    }

    return $pdo;
}

function buscarUsuarioPorEmail(string $email): array|false {
    $pdo  = getConnection();
    $stmt = $pdo->prepare('SELECT id, nome, email, senha_hash FROM usuarios WHERE email = :email LIMIT 1');
    $stmt->execute([':email' => $email]);
    return $stmt->fetch();
}

/**
 * Registra um novo usuário no banco de dados.
 * Retorna true em caso de sucesso ou false em caso de erro.
 */
function registrarUsuario(string $nome, string $email, string $senha): bool {
    $pdo       = getConnection();
    $senhaHash = password_hash($senha, PASSWORD_BCRYPT, ['cost' => 12]);

    $stmt = $pdo->prepare(
        'INSERT INTO usuarios (nome, email, senha_hash) VALUES (:nome, :email, :senha_hash)'
    );

    return $stmt->execute([
        ':nome'       => $nome,
        ':email'      => $email,
        ':senha_hash' => $senhaHash,
    ]);
}

/**
 * Inicia sessão do usuário após login bem-sucedido.
 */
function iniciarSessao(array $usuario): void {
    session_regenerate_id(true); // previne session fixation
    $_SESSION['usuario_id']    = $usuario['id'];
    $_SESSION['usuario_nome']  = $usuario['nome'];
    $_SESSION['usuario_email'] = $usuario['email'];
}

/**
 * Verifica se há um usuário logado.
 */
function estaLogado(): bool {
    return !empty($_SESSION['usuario_id']);
}

/**
 * Encerra a sessão do usuário.
 */
function fazerLogout(): void {
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $p = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000,
            $p['path'], $p['domain'], $p['secure'], $p['httponly']
        );
    }
    session_destroy();
}

function garantirTabelaFavoritos(): void {
    $pdo = getConnection();
    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS favoritos (
            id INT AUTO_INCREMENT PRIMARY KEY,
            usuario_id INT NOT NULL,
            item_slug VARCHAR(120) NOT NULL,
            item_titulo VARCHAR(255) NOT NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY usuario_item (usuario_id, item_slug),
            CONSTRAINT favoritos_usuario_fk
                FOREIGN KEY (usuario_id) REFERENCES usuarios(id)
                ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci'
    );
}

function listarFavoritos(int $usuarioId): array {
    garantirTabelaFavoritos();
    $pdo = getConnection();
    $stmt = $pdo->prepare('SELECT item_slug, item_titulo FROM favoritos WHERE usuario_id = :usuario_id ORDER BY created_at DESC');
    $stmt->execute([':usuario_id' => $usuarioId]);

    $favoritos = [];
    foreach ($stmt->fetchAll() as $favorito) {
        $favoritos[$favorito['item_slug']] = $favorito['item_titulo'];
    }

    return $favoritos;
}

function alternarFavorito(int $usuarioId, string $slug, string $titulo): void {
    garantirTabelaFavoritos();
    $pdo = getConnection();

    $stmt = $pdo->prepare('SELECT id FROM favoritos WHERE usuario_id = :usuario_id AND item_slug = :item_slug LIMIT 1');
    $stmt->execute([
        ':usuario_id' => $usuarioId,
        ':item_slug'  => $slug,
    ]);

    if ($stmt->fetch()) {
        $delete = $pdo->prepare('DELETE FROM favoritos WHERE usuario_id = :usuario_id AND item_slug = :item_slug');
        $delete->execute([
            ':usuario_id' => $usuarioId,
            ':item_slug'  => $slug,
        ]);
        return;
    }

    $insert = $pdo->prepare('INSERT INTO favoritos (usuario_id, item_slug, item_titulo) VALUES (:usuario_id, :item_slug, :item_titulo)');
    $insert->execute([
        ':usuario_id'  => $usuarioId,
        ':item_slug'   => $slug,
        ':item_titulo' => $titulo,
    ]);
}

function renderBotaoFavorito(string $slug, string $titulo, array $favoritos): string {
    $slugSeguro = htmlspecialchars($slug, ENT_QUOTES, 'UTF-8');
    $tituloSeguro = htmlspecialchars($titulo, ENT_QUOTES, 'UTF-8');

    if (!estaLogado()) {
        return '';
    }

    $favoritado = array_key_exists($slug, $favoritos);
    $classe = $favoritado ? 'favorite-button is-active' : 'favorite-button';
    $aria = $favoritado ? 'true' : 'false';
    $label = $favoritado ? 'Remover dos favoritos' : 'Adicionar aos favoritos';

    return '<form class="favorite-form" method="POST" action="index.php#roteiros">
        <input type="hidden" name="acao" value="alternar_favorito">
        <input type="hidden" name="favorito_slug" value="' . $slugSeguro . '">
        <input type="hidden" name="favorito_titulo" value="' . $tituloSeguro . '">
        <button class="' . $classe . '" type="submit" aria-pressed="' . $aria . '" title="' . $label . '" aria-label="' . $label . ': ' . $tituloSeguro . '">&#9733;</button>
    </form>';
}

// ----------------------------------------------------------
// 5. PROCESSAMENTO DAS REQUISIÇÕES (POST)
// ----------------------------------------------------------
$erro    = '';
$sucesso = '';
$acao    = $_POST['acao'] ?? '';

// --- Logout ---
if ($acao === 'logout') {
    fazerLogout();
    header('Location: index.php');
    exit;
}

// --- Favoritos ---
if ($acao === 'alternar_favorito') {
    if (!estaLogado()) {
        $erro = 'Entre na sua conta para favoritar rolês.';
    } else {
        $slug = preg_replace('/[^a-z0-9_-]/i', '', $_POST['favorito_slug'] ?? '');
        $titulo = trim($_POST['favorito_titulo'] ?? '');

        if ($slug === '' || $titulo === '') {
            $erro = 'Não foi possível identificar o rolê escolhido.';
        } else {
            try {
                alternarFavorito((int) $_SESSION['usuario_id'], $slug, mb_substr($titulo, 0, 255));
                header('Location: index.php#roteiros');
                exit;
            } catch (PDOException $e) {
                $erro = 'Erro ao salvar favorito. Verifique o banco de dados.';
            }
        }
    }
}

// --- Login ---
if ($acao === 'login') {
    $email = trim($_POST['email'] ?? '');
    $senha = $_POST['senha'] ?? '';

    if (empty($email) || empty($senha)) {
        $erro = 'Preencha e-mail e senha.';
    } else {
        // Tente conectar ao banco; se falhar, mostre mensagem amigável
        try {
            $usuario = buscarUsuarioPorEmail($email);

            if ($usuario && password_verify($senha, $usuario['senha_hash'])) {
                iniciarSessao($usuario);
                header('Location: index.php');
                exit;
            } else {
                $erro = 'E-mail ou senha incorretos.';
            }
        } catch (PDOException $e) {
            // Em produção, registre o erro em log em vez de exibir.
            // error_log($e->getMessage());
            $erro = 'Erro ao conectar ao banco de dados. Verifique as configurações.';
        }
    }
}

// --- Registro ---
if ($acao === 'registrar') {
    $nome  = trim($_POST['nome']  ?? '');
    $email = trim($_POST['email'] ?? '');
    $senha = $_POST['senha']      ?? '';
    $conf  = $_POST['confirmar']  ?? '';

    if (empty($nome) || empty($email) || empty($senha)) {
        $erro = 'Preencha todos os campos.';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $erro = 'E-mail inválido.';
    } elseif (strlen($senha) < 6) {
        $erro = 'A senha deve ter pelo menos 6 caracteres.';
    } elseif ($senha !== $conf) {
        $erro = 'As senhas não conferem.';
    } else {
        try {
            if (buscarUsuarioPorEmail($email)) {
                $erro = 'Este e-mail já está cadastrado.';
            } else {
                registrarUsuario($nome, $email, $senha);
                $sucesso = 'Cadastro realizado! Agora faça o login.';
            }
        } catch (PDOException $e) {
            $erro = 'Erro ao salvar os dados. Verifique as configurações do banco.';
        }
    }
}

// ----------------------------------------------------------
// 6. VARIÁVEIS PARA O TEMPLATE HTML
// ----------------------------------------------------------
$usuarioLogado = estaLogado() ? $_SESSION['usuario_nome'] : null;
$favoritosUsuario = [];
if (estaLogado()) {
    try {
        $favoritosUsuario = listarFavoritos((int) $_SESSION['usuario_id']);
    } catch (PDOException $e) {
        $erro = $erro ?: 'Erro ao carregar favoritos.';
    }
}
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Conexão Jampa | Em breve</title>
    <style>
        :root {
            --sky-blue: #8fc8dd;
            --deep-blue: #0d536b;
            --sun-gold: #f4b247;
            --coral: #f2614c;
            --sand: #ebe3d0;
            --off-white: #f8f4ea;
            --sea-foam: #9fd3bb;
            --palm-green: #3c6f2a;
            --peach: #f5bea0;
            --driftwood: #8b7765;
            --concrete: #b9b8af;
            --ink: #123044;
        }

        * { box-sizing: border-box; }

        body {
            margin: 0;
            min-height: 100vh;
            color: var(--ink);
            font-family: Arial, Helvetica, sans-serif;
            background:
                linear-gradient(90deg, rgba(143,200,221,.34), transparent 36%),
                linear-gradient(180deg, var(--off-white), var(--sand));
        }

        a { color: inherit; text-decoration: none; }

        /* ---- Layout shell ---- */
        .site-shell { min-height: 100vh; overflow: hidden; position: relative; }

        .city-shape {
            position: absolute; inset: auto 0 0 0; height: 42vh;
            opacity: .5; pointer-events: none;
            background:
                linear-gradient(to top,rgba(185,184,175,.8),rgba(185,184,175,.8)) 0 100%/10% 72% no-repeat,
                linear-gradient(to top,rgba(235,227,208,.98),rgba(235,227,208,.98)) 8% 100%/12% 88% no-repeat,
                linear-gradient(to top,rgba(185,184,175,.75),rgba(185,184,175,.75)) 22% 100%/13% 54% no-repeat,
                linear-gradient(to top,rgba(13,83,107,.14),rgba(13,83,107,.14)) 35% 100%/6% 70% no-repeat,
                linear-gradient(to top,rgba(185,184,175,.72),rgba(185,184,175,.72)) 44% 100%/11% 64% no-repeat,
                linear-gradient(to top,rgba(235,227,208,.95),rgba(235,227,208,.95)) 63% 100%/13% 78% no-repeat,
                linear-gradient(to top,rgba(185,184,175,.78),rgba(185,184,175,.78)) 82% 100%/10% 56% no-repeat;
        }

        /* ---- Header ---- */
        header {
            width: min(1120px, calc(100% - 36px));
            margin: 0 auto; padding: 26px 0 0;
            display: flex; align-items: center;
            justify-content: space-between; gap: 24px;
            position: relative; z-index: 2;
        }

        .brand { display: flex; align-items: center; gap: 12px; min-width: 0; }

        .mark {
            width: 56px; aspect-ratio: 1;
            border: 3px solid var(--coral); border-radius: 50% 50% 46% 46%;
            display: grid; place-items: center; position: relative;
            background: var(--off-white); box-shadow: 0 8px 22px rgba(18,48,68,.12);
        }

        .mark::before, .mark::after {
            content: ""; position: absolute; left: 50%;
            transform: translateX(-50%);
            border: 2px solid var(--coral); border-bottom: 0; border-radius: 50% 50% 0 0;
        }
        .mark::before { top:-9px; width:24px; height:10px; }
        .mark::after  { top:-15px; width:34px; height:16px; opacity:.75; }

        .lighthouse {
            width:16px; height:32px; border:3px solid var(--ink); border-top:0;
            position:relative;
            background: linear-gradient(var(--coral) 0 22%, var(--off-white) 22% 45%, var(--deep-blue) 45% 64%, var(--off-white) 64%);
            clip-path: polygon(28% 0,72% 0,100% 100%,0 100%);
        }
        .lighthouse::before {
            content:""; position:absolute; top:-10px; left:50%; width:16px; height:10px;
            transform:translateX(-50%); background:var(--deep-blue); border-radius:8px 8px 0 0;
        }

        .brand-text strong { display:block; color:var(--coral); font-size:clamp(1.35rem,3vw,2rem); line-height:.95; text-transform:uppercase; }
        .brand-text span   { display:block; color:var(--deep-blue); font-family:Georgia,"Times New Roman",serif; font-size:1.05rem; font-style:italic; font-weight:700; margin-top:2px; }

        nav { display:flex; align-items:center; gap:18px; color:var(--deep-blue); font-size:.82rem; font-weight:800; text-transform:uppercase; }

        .nav-button { border:2px solid var(--deep-blue); border-radius:999px; padding:10px 16px; background:rgba(248,244,234,.72); }

        /* ---- Login nav button (destaque coral) ---- */
        .nav-login {
            border:2px solid var(--coral); border-radius:999px; padding:10px 18px;
            background:var(--coral); color:var(--off-white) !important;
            font-size:.82rem; font-weight:800; text-transform:uppercase;
            cursor:pointer; transition:background .2s, color .2s;
        }
        .nav-login:hover { background:var(--deep-blue); border-color:var(--deep-blue); }

        /* ---- Usuário logado no header ---- */
        .user-badge {
            display:flex; align-items:center; gap:10px;
            font-size:.82rem; font-weight:800; color:var(--deep-blue); text-transform:uppercase;
        }
        .user-badge .avatar {
            width:34px; height:34px; border-radius:50%;
            background:var(--deep-blue); color:var(--off-white);
            display:grid; place-items:center; font-size:1rem; font-weight:900;
        }
        .user-badge .btn-logout {
            border:2px solid var(--coral); border-radius:999px;
            padding:7px 14px; color:var(--coral); background:transparent;
            font-size:.78rem; font-weight:800; text-transform:uppercase;
            cursor:pointer; transition:background .2s, color .2s;
        }
        .user-badge .btn-logout:hover { background:var(--coral); color:var(--off-white); }

        /* ---- Main / Hero ---- */
        main { width:min(1120px, calc(100% - 36px)); margin:0 auto; position:relative; z-index:1; }

        .hero {
            min-height:calc(100vh - 90px);
            display:grid; grid-template-columns:minmax(0,1.02fr) minmax(300px,.98fr);
            align-items:center; gap:clamp(28px,5vw,72px); padding:48px 0 78px;
        }

        .eyebrow { color:var(--deep-blue); font-size:.86rem; font-weight:900; text-transform:uppercase; margin:0 0 18px; }

        h1 { margin:0; color:var(--ink); font-size:clamp(2.65rem,8vw,6.4rem); line-height:.9; text-transform:uppercase; max-width:760px; }

        .hero-copy { max-width:560px; margin:24px 0 0; color:#405365; font-size:clamp(1rem,2.1vw,1.18rem); line-height:1.65; }

        .actions { display:flex; flex-wrap:wrap; gap:12px; margin-top:34px; }

        .button { min-height:48px; display:inline-flex; align-items:center; justify-content:center; gap:10px; border-radius:999px; border:2px solid transparent; padding:13px 20px; font-size:.92rem; font-weight:900; text-transform:uppercase; white-space:nowrap; }
        .button.primary  { color:var(--off-white); background:var(--coral); box-shadow:0 14px 28px rgba(242,97,76,.24); }
        .button.secondary{ color:var(--deep-blue); border-color:var(--deep-blue); background:rgba(248,244,234,.75); }

        .hero-art { min-height:560px; position:relative; align-self:stretch; }

        .sun  { position:absolute; top:8%; right:8%; width:min(43vw,350px); aspect-ratio:1; border-radius:50%; background:var(--sun-gold); box-shadow:0 18px 60px rgba(244,178,71,.35); }

        .ocean {
            position:absolute; right:-6vw; bottom:8%; width:min(56vw,530px); height:250px;
            background: radial-gradient(circle at 20% 20%,rgba(255,255,255,.38),transparent 32%), linear-gradient(135deg,var(--sea-foam),var(--sky-blue) 54%,var(--deep-blue));
            border-radius:18px 0 0 18px; overflow:hidden; box-shadow:0 26px 60px rgba(18,48,68,.16);
        }
        .ocean::before,.ocean::after { content:""; position:absolute; left:-6%; right:-6%; height:70px; border-radius:50%; border-top:4px solid rgba(248,244,234,.7); }
        .ocean::before { top:70px; }
        .ocean::after  { top:118px; border-top-color:rgba(248,244,234,.48); }

        .postcard { position:absolute; left:0; bottom:18%; width:min(78vw,360px); padding:18px; background:rgba(248,244,234,.93); border:2px solid rgba(18,48,68,.12); box-shadow:0 22px 48px rgba(18,48,68,.18); transform:rotate(-4deg); }

        .map-lines {
            height:170px;
            background:
                linear-gradient(90deg,transparent 48%,rgba(13,83,107,.16) 49% 51%,transparent 52%),
                linear-gradient(0deg,transparent 48%,rgba(13,83,107,.16) 49% 51%,transparent 52%),
                radial-gradient(circle at 30% 36%,var(--coral) 0 8px,transparent 9px),
                radial-gradient(circle at 66% 62%,var(--deep-blue) 0 7px,transparent 8px),
                linear-gradient(135deg,rgba(159,211,187,.82),rgba(143,200,221,.52));
            background-size:48px 48px,42px 42px,auto,auto,auto;
            border-radius:6px; position:relative;
        }
        .map-lines::before { content:""; position:absolute; inset:22px; border:3px dashed rgba(242,97,76,.64); border-left-color:transparent; border-bottom-color:transparent; border-radius:50%; transform:rotate(16deg); }

        .postcard-footer { display:flex; justify-content:space-between; align-items:center; gap:16px; margin-top:14px; color:var(--deep-blue); font-size:.78rem; font-weight:900; text-transform:uppercase; }

        .ticket { position:absolute; right:0; top:24%; width:min(72vw,320px); padding:18px 18px 20px; color:var(--off-white); background:var(--deep-blue); box-shadow:0 22px 48px rgba(18,48,68,.24); transform:rotate(5deg); }
        .ticket::before { content:""; position:absolute; inset:10px; border:2px solid rgba(248,244,234,.24); pointer-events:none; }
        .ticket p  { margin:0; color:var(--sky-blue); font-size:.76rem; font-weight:900; text-transform:uppercase; }
        .ticket strong { display:block; margin-top:10px; font-size:clamp(1.45rem,3vw,2.1rem); line-height:1; text-transform:uppercase; }

        .wave-line { width:100%; height:18px; margin-top:18px; background:radial-gradient(22px 12px at 11px 0,transparent 10px,var(--off-white) 11px 12px,transparent 13px) 0 0/44px 18px repeat-x; opacity:.88; }

        /* ---- Carousel ---- */
        .carousel { position:relative; overflow:hidden; margin:0 0 48px; border-radius:18px 0 0 18px; box-shadow:0 22px 48px rgba(18,48,68,.16); }
        .carousel-track { display:flex; transition:transform 0.4s ease; }
        .slide {
            min-width:100%;
            min-height:clamp(300px,44vw,520px);
            padding:clamp(28px,5vw,58px);
            display:flex;
            align-items:flex-end;
            color:var(--off-white);
            position:relative;
            overflow:hidden;
            background:linear-gradient(135deg,var(--deep-blue),var(--sky-blue));
        }
        .slide img {
            position:absolute;
            inset:0;
            width:100%;
            height:100%;
            object-fit:cover;
        }
        .slide::before {
            content:"";
            position:absolute;
            inset:0;
            background:
                linear-gradient(90deg,rgba(18,48,68,.86),rgba(18,48,68,.42) 58%,rgba(18,48,68,.18)),
                linear-gradient(180deg,rgba(18,48,68,.06),rgba(18,48,68,.78));
            z-index:1;
        }
        .slide::after {
            content:"";
            position:absolute;
            left:-8%;
            right:-8%;
            bottom:-24px;
            height:150px;
            border-radius:50% 50% 0 0;
            border-top:5px solid rgba(248,244,234,.55);
            box-shadow:0 -34px 0 -29px rgba(248,244,234,.32),0 -68px 0 -63px rgba(248,244,234,.22);
            z-index:1;
        }
        .slide-cultural { background:linear-gradient(135deg,#7b3f5c,var(--coral)); }
        .slide-natureza { background:linear-gradient(135deg,var(--palm-green),var(--sea-foam)); }
        .slide-noite { background:linear-gradient(135deg,#182a4a,var(--deep-blue)); }
        .slide-content { max-width:620px; position:relative; z-index:2; }
        .slide-kicker { display:block; margin-bottom:12px; color:var(--sun-gold); font-size:.78rem; font-weight:900; text-transform:uppercase; }
        .slide h2 { margin:0; font-size:clamp(1.9rem,5vw,4.2rem); line-height:.95; text-transform:uppercase; }
        .slide p { max-width:560px; margin:18px 0 0; font-size:clamp(.98rem,1.7vw,1.14rem); line-height:1.55; color:rgba(248,244,234,.9); }
        #prev, #next { position:absolute; top:50%; z-index:5; transform:translateY(-50%); width:44px; aspect-ratio:1; border:0; border-radius:50%; color:var(--off-white); background:rgba(18,48,68,.72); cursor:pointer; font-size:1.2rem; line-height:1; }
        #prev { left:10px; }
        #next { right:10px; }

        /* ---- Event Hall ---- */
        .event-hall { margin:0 0 72px; position:relative; z-index:2; }
        .event-hall-header { display:flex; align-items:center; justify-content:space-between; gap:16px; margin-bottom:18px; }
        .event-hall-header h2 { margin:0; color:var(--ink); font-size:clamp(1.25rem,2.5vw,1.75rem); }
        .event-hall-header p { max-width:620px; margin:6px 0 0; color:#536170; font-size:.88rem; line-height:1.55; }
        .event-grid { display:grid; grid-template-columns:repeat(5,minmax(0,1fr)); gap:16px; }
        .event-card { min-width:0; outline:none; }
        .event-card-head {
            display:flex;
            align-items:flex-start;
            justify-content:space-between;
            gap:8px;
        }
        .event-card-head h3 { flex:1; }
        .favorite-form { margin:8px 0 0; }
        .favorite-button {
            width:34px;
            aspect-ratio:1;
            display:grid;
            place-items:center;
            border:2px solid rgba(18,48,68,.18);
            border-radius:50%;
            background:rgba(248,244,234,.92);
            color:#9a9a8a;
            cursor:pointer;
            font-size:1.05rem;
            line-height:1;
            transition:background .2s,border-color .2s,color .2s,transform .2s,box-shadow .2s;
        }
        .favorite-button:hover,
        .favorite-button:focus-visible,
        .favorite-button.is-active {
            background:var(--sun-gold);
            border-color:var(--sun-gold);
            color:var(--ink);
            transform:translateY(-2px);
            box-shadow:0 10px 20px rgba(244,178,71,.28);
        }
        .event-banner {
            position:relative;
            aspect-ratio:16/9;
            overflow:hidden;
            border-radius:8px;
            background:linear-gradient(135deg,rgba(242,97,76,.18),rgba(143,200,221,.36)),var(--off-white);
            box-shadow:0 14px 30px rgba(18,48,68,.1);
        }
        .event-banner img {
            display:block;
            width:100%;
            height:100%;
            object-fit:cover;
            transition:transform .35s ease;
        }
        .event-card:hover .event-banner img { transform:scale(1.05); }
        .event-banner::before {
            content:"";
            position:absolute;
            inset:12px;
            border:2px solid rgba(248,244,234,.45);
            z-index:2;
        }
        .event-banner::after {
            content:attr(data-label);
            position:absolute;
            inset:0;
            display:grid;
            place-items:center;
            padding:14px;
            color:var(--off-white);
            background:linear-gradient(145deg,rgba(18,48,68,.1),rgba(18,48,68,.54));
            font-size:.78rem;
            font-weight:900;
            text-align:center;
            text-transform:uppercase;
            z-index:1;
        }
        .event-banner.festival { background:linear-gradient(135deg,var(--coral),var(--sun-gold)); }
        .event-banner.artesanato { background:linear-gradient(135deg,var(--deep-blue),var(--sea-foam)); }
        .event-banner.carnaval { background:linear-gradient(135deg,#8a3f78,var(--coral)); }
        .event-banner.saojoao { background:linear-gradient(135deg,#276d48,var(--sun-gold)); }
        .event-banner.cinema { background:linear-gradient(135deg,#1f2c45,#6d7891); }
        .event-banner.cultura { background:linear-gradient(135deg,var(--palm-green),var(--sky-blue)); }
        .event-card h3 { margin:10px 0 4px; color:var(--ink); font-size:.9rem; line-height:1.25; }
        .event-card p, .event-card time { display:block; margin:0; color:#536170; font-size:.72rem; line-height:1.45; }
        .event-card time { margin-top:3px; }
        .event-card strong { display:block; margin-top:6px; color:var(--coral); font-size:.68rem; text-transform:uppercase; }
        .event-extra {
            max-height:0;
            margin-top:0;
            opacity:0;
            overflow:hidden;
            transform:translateY(-4px);
            transition:max-height .28s ease,opacity .24s ease,margin-top .24s ease,transform .24s ease;
        }
        .event-extra span {
            display:block;
            padding:10px 12px;
            border-left:4px solid var(--coral);
            background:rgba(248,244,234,.9);
            color:#405365;
            font-size:.72rem;
            line-height:1.45;
            box-shadow:0 10px 22px rgba(18,48,68,.08);
        }
        .event-card.has-extra:hover .event-extra,
        .event-card.has-extra:focus .event-extra,
        .event-card.has-extra.is-open .event-extra {
            max-height:120px;
            margin-top:10px;
            opacity:1;
            transform:translateY(0);
        }
        .favorites-panel {
            margin:-34px 0 56px;
            padding:18px 20px;
            border-left:5px solid var(--sun-gold);
            background:rgba(248,244,234,.86);
            box-shadow:0 16px 32px rgba(18,48,68,.08);
        }
        .favorites-panel h2 {
            margin:0 0 10px;
            color:var(--ink);
            font-size:1.05rem;
        }
        .favorites-list {
            display:flex;
            flex-wrap:wrap;
            gap:8px;
            margin:0;
            padding:0;
            list-style:none;
        }
        .favorites-list li {
            border-radius:999px;
            padding:8px 12px;
            background:white;
            color:#405365;
            font-size:.78rem;
            font-weight:800;
            box-shadow:0 8px 18px rgba(18,48,68,.07);
        }
        .favorites-empty {
            margin:0;
            color:#536170;
            font-size:.86rem;
        }

        /* ---- Footer ---- */
        footer {
            position:relative;
            z-index:2;
            background:var(--ink);
            color:var(--off-white);
            margin-top:36px;
            padding:34px 18px;
        }
        .site-footer-inner {
            width:min(1120px,100%);
            margin:0 auto;
            display:flex;
            align-items:center;
            justify-content:space-between;
            gap:24px;
        }
        .footer-brand strong {
            display:block;
            color:var(--coral);
            font-size:1.2rem;
            line-height:1;
            text-transform:uppercase;
        }
        .footer-brand span,
        .footer-contact span {
            display:block;
            margin-top:6px;
            color:rgba(248,244,234,.74);
            font-size:.82rem;
            line-height:1.45;
        }
        .footer-contact {
            display:grid;
            justify-items:end;
            gap:10px;
            text-align:right;
        }
        .footer-contact > span {
            margin-top:0;
        }
        .footer-links {
            display:flex;
            flex-wrap:wrap;
            justify-content:flex-end;
            gap:10px;
        }
        .footer-links a {
            display:inline-flex;
            align-items:center;
            justify-content:center;
            min-height:40px;
            border:2px solid rgba(248,244,234,.22);
            border-radius:999px;
            padding:10px 14px;
            color:var(--off-white);
            font-size:.78rem;
            font-weight:900;
            text-transform:uppercase;
            transition:background .2s,border-color .2s,transform .2s;
        }
        .footer-icon {
            width:16px;
            height:16px;
            margin-right:8px;
            flex:0 0 auto;
        }
        .footer-links a:hover {
            background:var(--coral);
            border-color:var(--coral);
            transform:translateY(-2px);
        }

        /* ======================================================
           MODAL DE LOGIN / CADASTRO
        ====================================================== */
        .modal-overlay {
            display:none; position:fixed; inset:0; z-index:999;
            background:rgba(18,48,68,.55); backdrop-filter:blur(4px);
            align-items:center; justify-content:center; padding:20px;
        }
        .modal-overlay.open { display:flex; }

        .modal {
            width:100%; max-width:430px;
            background:var(--off-white); border-top:6px solid var(--coral);
            box-shadow:0 32px 72px rgba(18,48,68,.26);
            padding:36px 32px 32px; position:relative;
            animation:slideUp .28s ease;
        }
        @keyframes slideUp { from{transform:translateY(24px);opacity:0} to{transform:translateY(0);opacity:1} }

        .modal-close {
            position:absolute; top:14px; right:16px;
            background:none; border:none; font-size:1.5rem;
            color:var(--driftwood); cursor:pointer; line-height:1;
        }
        .modal-close:hover { color:var(--coral); }

        .modal-tabs { display:flex; gap:0; margin-bottom:28px; border-bottom:2px solid var(--sand); }
        .modal-tab  {
            flex:1; padding:10px; text-align:center; font-size:.85rem;
            font-weight:900; text-transform:uppercase; color:var(--concrete);
            cursor:pointer; background:none; border:none;
            border-bottom:3px solid transparent; margin-bottom:-2px;
            transition:color .2s, border-color .2s;
        }
        .modal-tab.active { color:var(--coral); border-bottom-color:var(--coral); }

        .modal-panel { display:none; }
        .modal-panel.active { display:block; }

        .modal h2 { margin:0 0 4px; font-size:1.35rem; color:var(--ink); text-transform:uppercase; }
        .modal .sub { margin:0 0 22px; font-size:.88rem; color:var(--driftwood); }

        /* ---- Alerta de erro / sucesso ---- */
        .alert {
            padding:11px 14px; margin-bottom:18px; font-size:.86rem; font-weight:700;
            border-left:4px solid; border-radius:2px;
        }
        .alert.error   { background:rgba(242,97,76,.1); border-color:var(--coral); color:#8b2a1a; }
        .alert.success { background:rgba(159,211,187,.2); border-color:var(--sea-foam); color:var(--palm-green); }

        /* ---- Form fields ---- */
        .field       { margin-bottom:16px; }
        .field label { display:block; font-size:.8rem; font-weight:900; text-transform:uppercase; color:var(--deep-blue); margin-bottom:6px; }
        .field input {
            width:100%; padding:12px 14px; font-size:.95rem;
            border:2px solid var(--sand); border-radius:4px;
            background:white; color:var(--ink); outline:none;
            transition:border-color .2s;
        }
        .field input:focus { border-color:var(--deep-blue); }

        .btn-submit {
            width:100%; padding:14px; margin-top:6px;
            background:var(--coral); color:var(--off-white);
            font-size:.92rem; font-weight:900; text-transform:uppercase;
            border:none; border-radius:999px; cursor:pointer;
            box-shadow:0 10px 24px rgba(242,97,76,.22);
            transition:background .2s;
        }
        .btn-submit:hover { background:var(--deep-blue); }

        /* ---- Boas-vindas quando logado ---- */
        .welcome-banner {
            background:rgba(159,211,187,.22); border-left:5px solid var(--sea-foam);
            padding:14px 18px; margin-bottom:28px; font-size:.95rem; color:var(--palm-green); font-weight:700;
        }

        /* ======================================================
           RESPONSIVE
        ====================================================== */
        @media (max-width:820px) {
            header { align-items:flex-start; flex-direction:column; }
            nav    { width:100%; justify-content:space-between; overflow-x:auto; padding-bottom:4px; }
            .hero  { grid-template-columns:1fr; padding-top:42px; }
            .hero-art { min-height:430px; order:-1; }
            .sun   { right:2%; width:250px; }
            .ocean { width:92vw; right:-18px; bottom:0; }
            .ticket   { top:26%; right:4%; width:min(76vw,270px); }
            .postcard { width:min(78vw,300px); bottom:12%; }
            .carousel { border-radius:12px; }
            .event-grid { grid-auto-columns:minmax(220px,72vw); grid-auto-flow:column; grid-template-columns:none; overflow-x:auto; padding-bottom:12px; scroll-snap-type:x mandatory; }
            .event-card { scroll-snap-align:start; }
        }
        @media (max-width:520px) {
            main,header { width:min(100% - 28px,1120px); }
            nav a:not(.nav-button):not(.nav-login) { display:none; }
            .nav-button { width:100%; text-align:center; }
            .actions { align-items:stretch; flex-direction:column; }
            .button  { width:100%; white-space:normal; }
            .hero-art { min-height:390px; }
            .map-lines { height:135px; }
            .modal    { padding:28px 20px 24px; }
            .event-hall-header { align-items:flex-start; flex-direction:column; gap:6px; }
            .site-footer-inner { align-items:flex-start; flex-direction:column; }
            .footer-contact { justify-items:start; text-align:left; width:100%; }
            .footer-links { justify-content:flex-start; width:100%; }
        }
    </style>
</head>
<body>
<div class="site-shell">
    <div class="city-shape" aria-hidden="true"></div>

    <header>
        <a class="brand" href="index.php" aria-label="Conexão Jampa">
            <span class="mark" aria-hidden="true">
                <span class="lighthouse"></span>
            </span>
            <span class="brand-text">
                <strong>Conexão</strong>
                <span>jampa</span>
            </span>
        </a>

        <nav aria-label="Navegação principal">
            <a href="#experiencias">Experiências</a>
            <a href="#roteiros">Roteiros</a>
            <a class="nav-button" href="mailto:contato@conexaojampa.com.br">Quero saber</a>

            <?php if ($usuarioLogado): ?>
                <!-- Usuário logado: exibe nome + botão sair -->
                <div class="user-badge">
                    <span class="avatar"><?= mb_strtoupper(mb_substr($usuarioLogado, 0, 1)) ?></span>
                    <span><?= htmlspecialchars($usuarioLogado) ?></span>
                    <form method="POST" action="index.php" style="margin:0">
                        <input type="hidden" name="acao" value="logout">
                        <button type="submit" class="btn-logout">Sair</button>
                    </form>
                </div>
            <?php else: ?>
                <!-- Usuário deslogado: botão Entrar -->
                <button class="nav-login" onclick="abrirModal('login')">Entrar</button>
            <?php endif; ?>
        </nav>
    </header>

    <main>
        <?php if ($usuarioLogado): ?>
        <div class="welcome-banner" style="width:100%;margin-top:14px;">
            👋 Bem-vindo de volta, <?= htmlspecialchars($usuarioLogado) ?>! Você está conectado ao Conexão Jampa.
        </div>
        <section class="favorites-panel" aria-labelledby="favorites-title">
            <h2 id="favorites-title">Meus favoritos</h2>
            <?php if ($favoritosUsuario): ?>
                <ul class="favorites-list">
                    <?php foreach ($favoritosUsuario as $favoritoTitulo): ?>
                        <li>★ <?= htmlspecialchars($favoritoTitulo) ?></li>
                    <?php endforeach; ?>
                </ul>
            <?php else: ?>
                <p class="favorites-empty">Clique na estrela dos rolês para guardar seus favoritos aqui.</p>
            <?php endif; ?>
        </section>
        <?php endif; ?>

        <section class="hero" aria-labelledby="hero-title">
            <div class="hero-content">
                <p class="eyebrow">Entardecer tropical, horizonte urbano e frescor do litoral</p>
                <h1 id="hero-title">Sua próxima conexão com Jampa está chegando.</h1>
                <p class="hero-copy">
                    Um guia afetivo para viver João Pessoa por dentro: praias, sabores,
                    cultura, rotas urbanas e encontros que fazem a cidade ficar na memória.
                </p>
                <div class="actions">
                    <a class="button primary" href="mailto:contato@conexaojampa.com.br">Entrar na lista</a>
                    <a class="button secondary" href="#experiencias">Ver prévia</a>
                </div>
            </div>

            <div class="hero-art" aria-hidden="true">
                <div class="sun"></div>
                <div class="ocean"></div>
                <div class="ticket">
                    <p>Pré-lançamento</p>
                    <strong>Rotas, achados e mar</strong>
                    <div class="wave-line"></div>
                </div>
                <div class="postcard">
                    <div class="map-lines"></div>
                    <div class="postcard-footer">
                        <span>Mapa local</span>
                        <span>João Pessoa</span>
                    </div>
                </div>
            </div>
        </section>

        <div class="carousel" id="experiencias">
            <div class="carousel-track" id="track">
                <article class="slide slide-cultural">
                    <img src="assets/eventos/fest-verao.jpg" alt="Público em show do Fest Verão Paraíba">
                    <div class="slide-content">
                        <span class="slide-kicker">Eventos e festivais</span>
                        <h2>João Pessoa em festa o ano todo</h2>
                        <p>Do Fest Verão Paraíba ao São João Multicultural, a cidade mistura shows, forró, cinema, artesanato, gastronomia e cultura popular em diferentes meses do ano.</p>
                    </div>
                </article>
                <article class="slide slide-natureza">
                    <img src="assets/eventos/centro-historico.jpg" alt="Vista do Centro Histórico de João Pessoa">
                    <div class="slide-content">
                        <span class="slide-kicker">Passeios imperdíveis</span>
                        <h2>Mar, mirantes e Centro Histórico</h2>
                        <p>Piscinas Naturais do Seixas, Ilha de Areia Vermelha, Farol do Cabo Branco, Hotel Globo e Centro Cultural São Francisco entram no roteiro de quem quer ver Jampa por inteiro.</p>
                    </div>
                </article>
                <article class="slide slide-noite">
                    <img src="assets/eventos/sabadinho.jpg" alt="Sabadinho Bom no Centro Histórico de João Pessoa">
                    <div class="slide-content">
                        <span class="slide-kicker">Sabores e noite</span>
                        <h2>Da tapioca ao samba de rua</h2>
                        <p>Mangai, Tábua de Carne, Mercado de Tambaú, Sabadinho Bom, Vila do Porto e bares da orla ajudam a transformar a viagem em encontro, comida boa e música ao vivo.</p>
                    </div>
                </article>
            </div>
            <button id="prev" type="button" aria-label="Imagem anterior">◀</button>
            <button id="next" type="button" aria-label="Próxima imagem">▶</button>
        </div>

        <section class="event-hall" aria-labelledby="event-hall-title">
            <div class="event-hall-header">
                <div>
                    <h2 id="event-hall-title">Eventos em destaque</h2>
                    <p>Seleção do Guia Turístico de João Pessoa 2025/2026. As datas podem mudar a cada edição, então vale confirmar na agenda oficial antes de ir.</p>
                </div>
            </div>
            <div class="event-grid">
                <article class="event-card has-extra" tabindex="0">
                    <div class="event-banner festival" data-label="Jan">
                        <img src="assets/eventos/fest-verao.jpg" alt="Público em show do Fest Verão Paraíba" loading="lazy">
                    </div>
                    <div class="event-card-head"><h3>Fest Verão Paraíba</h3><?= renderBotaoFavorito('fest-verao-paraiba', 'Fest Verão Paraíba', $favoritosUsuario) ?></div>
                    <p>Grandes shows na Praia de Ponta de Campina, em Cabedelo.</p>
                    <strong>Verão</strong>
                    <div class="event-extra"><span>Em janeiro, geralmente aos sábados, com arena montada na Praia de Ponta de Campina. Confirme atrações e ingressos no perfil oficial.</span></div>
                </article>
                <article class="event-card has-extra" tabindex="0">
                    <div class="event-banner artesanato" data-label="Jan/Fev">
                        <img src="assets/eventos/salao-artesanato.jpeg" alt="Peças expostas no Salão do Artesanato Paraibano" loading="lazy">
                    </div>
                    <div class="event-card-head"><h3>Salão do Artesanato</h3><?= renderBotaoFavorito('salao-do-artesanato', 'Salão do Artesanato', $favoritosUsuario) ?></div>
                    <p>Megaevento na Orla de Tambaú com peças únicas de artesãos paraibanos.</p>
                    <strong>Artesanato</strong>
                    <div class="event-extra"><span>Edição de verão costuma funcionar no fim da tarde/noite, na área do Hotel Tambaú. Entrada gratuita ou solidária, conforme a edição.</span></div>
                </article>
                <article class="event-card has-extra" tabindex="0">
                    <div class="event-banner carnaval" data-label="Fev">
                        <img src="assets/eventos/carnaval-muricocas.jpeg" alt="Desfile das Muriçocas do Miramar em João Pessoa" loading="lazy">
                    </div>
                    <div class="event-card-head"><h3>Folia de Rua e Carnaval</h3><?= renderBotaoFavorito('folia-de-rua-carnaval', 'Folia de Rua e Carnaval', $favoritosUsuario) ?></div>
                    <p>Prévias e blocos como Muriçocas do Miramar, Vumbora e Folia de Rua de Tambaú.</p>
                    <strong>Carnaval</strong>
                    <div class="event-extra"><span>Os blocos têm datas móveis antes do Carnaval. Muriçocas do Miramar tradicionalmente sai na quarta-feira pré-carnavalesca.</span></div>
                </article>
                <article class="event-card has-extra" tabindex="0">
                    <div class="event-banner saojoao" data-label="Jun">
                        <img src="assets/eventos/sao-joao.jpeg" alt="Apresentação de quadrilha junina no São João Multicultural" loading="lazy">
                    </div>
                    <div class="event-card-head"><h3>São João Multicultural</h3><?= renderBotaoFavorito('sao-joao-multicultural', 'São João Multicultural', $favoritosUsuario) ?></div>
                    <p>Mais de 40 dias de festa no Parque da Lagoa e nos bairros, com muito forró.</p>
                    <strong>Forró</strong>
                    <div class="event-extra"><span>Programação junina se espalha por maio e junho, com shows no Parque Solon de Lucena, quadrilhas e trios de forró nos bairros.</span></div>
                </article>
                <article class="event-card has-extra" tabindex="0">
                    <div class="event-banner cinema" data-label="Jul">
                        <img src="assets/eventos/fest-aruanda.jpeg" alt="Sessão do Fest Aruanda na Praia de Tambaú" loading="lazy">
                    </div>
                    <div class="event-card-head"><h3>Fest Aruanda</h3><?= renderBotaoFavorito('fest-aruanda', 'Fest Aruanda', $favoritosUsuario) ?></div>
                    <p>Festival de cinema nacional com mostras, debates com diretores e sessões gratuitas.</p>
                    <strong>Cinema</strong>
                    <div class="event-extra"><span>O guia aponta julho como referência, mas o festival pode ter datas móveis. Consulte a programação do Cinépolis Manaíra e polos parceiros.</span></div>
                </article>
                <article class="event-card has-extra" tabindex="0">
                    <div class="event-banner festival" data-label="Mar/Abr">
                        <img src="assets/eventos/restaurant-week.jpg" alt="Identidade visual da Paraíba Restaurant Week" loading="lazy">
                    </div>
                    <div class="event-card-head"><h3>Paraíba Restaurant Week</h3><?= renderBotaoFavorito('paraiba-restaurant-week', 'Paraíba Restaurant Week', $favoritosUsuario) ?></div>
                    <p>Menus a preço fixo em restaurantes da Grande João Pessoa.</p>
                    <strong>Gastronomia</strong>
                    <div class="event-extra"><span>Boa para conhecer restaurantes novos gastando menos. O guia cita valores médios de almoço e jantar, com reserva recomendada.</span></div>
                </article>
                <article class="event-card has-extra" tabindex="0">
                    <div class="event-banner carnaval" data-label="Mar">
                        <img src="assets/eventos/sabadinho.jpg" alt="Apresentação musical em João Pessoa" loading="lazy">
                    </div>
                    <div class="event-card-head"><h3>Rock na Usina</h3><?= renderBotaoFavorito('rock-na-usina', 'Rock na Usina', $favoritosUsuario) ?></div>
                    <p>Bandas independentes e feira criativa na Usina Energisa.</p>
                    <strong>Música</strong>
                    <div class="event-extra"><span>Evento ideal para descobrir a cena musical local. Fique de olho na agenda da Usina Energisa para horário e line-up.</span></div>
                </article>
                <article class="event-card has-extra" tabindex="0">
                    <div class="event-banner artesanato" data-label="Jul">
                        <img src="assets/eventos/brasil-mostra-brasil.jpg" alt="Multifeira Brasil Mostra Brasil em João Pessoa" loading="lazy">
                    </div>
                    <div class="event-card-head"><h3>Brasil Mostra Brasil</h3><?= renderBotaoFavorito('brasil-mostra-brasil', 'Brasil Mostra Brasil', $favoritosUsuario) ?></div>
                    <p>Multifeira de comércio, serviços, entretenimento e gastronomia.</p>
                    <strong>Feira</strong>
                    <div class="event-extra"><span>Costuma acontecer no Centro de Convenções, com estandes, negócios e praça de alimentação. Confira dias e transporte gratuito da edição.</span></div>
                </article>
                <article class="event-card has-extra" tabindex="0">
                    <div class="event-banner cinema" data-label="Ago">
                        <img src="assets/eventos/festa-neves.jpeg" alt="Celebração de Nossa Senhora das Neves em João Pessoa" loading="lazy">
                    </div>
                    <div class="event-card-head"><h3>Festa das Neves</h3><?= renderBotaoFavorito('festa-das-neves', 'Festa das Neves', $favoritosUsuario) ?></div>
                    <p>Programação religiosa e cultura popular no aniversário da cidade.</p>
                    <strong>Tradição</strong>
                    <div class="event-extra"><span>Evento de agosto ligado à padroeira e ao aniversário de João Pessoa, com missas, procissões, shows e feiras.</span></div>
                </article>
                <article class="event-card has-extra" tabindex="0">
                    <div class="event-banner cultura" data-label="Out">
                        <img src="assets/eventos/feira-pulgas.jpeg" alt="Feira das Pulgas no Parque Parahyba" loading="lazy">
                    </div>
                    <div class="event-card-head"><h3>Feira das Pulgas</h3><?= renderBotaoFavorito('feira-das-pulgas', 'Feira das Pulgas', $favoritosUsuario) ?></div>
                    <p>Brechós, antiguidades e shows gratuitos no Parque Parahyba I, no Bessa.</p>
                    <strong>Achados</strong>
                    <div class="event-extra"><span>Boa para garimpar itens vintage, comer ao ar livre e curtir programação cultural gratuita.</span></div>
                </article>
                <article class="event-card has-extra" tabindex="0">
                    <div class="event-banner saojoao" data-label="Nov/Dez">
                        <img src="assets/eventos/centro-historico.jpg" alt="Centro Histórico de João Pessoa" loading="lazy">
                    </div>
                    <div class="event-card-head"><h3>Virada Cultural</h3><?= renderBotaoFavorito('virada-cultural', 'Virada Cultural', $favoritosUsuario) ?></div>
                    <p>Maratona de 24h de cultura gratuita no Centro Histórico, orla e espaços culturais.</p>
                    <strong>Cultura</strong>
                    <div class="event-extra"><span>Novidade citada no guia. Quando confirmada, deve reunir música, artes, rua e programação gratuita em diferentes pontos.</span></div>
                </article>
                <article class="event-card has-extra" tabindex="0">
                    <div class="event-banner festival" data-label="Nov">
                        <img src="assets/eventos/mercado-peixe.jpg" alt="Mercado do Peixe de Tambaú em João Pessoa" loading="lazy">
                    </div>
                    <div class="event-card-head"><h3>Festival Gastronômico de Tambaú</h3><?= renderBotaoFavorito('festival-gastronomico-tambau', 'Festival Gastronômico de Tambaú', $favoritosUsuario) ?></div>
                    <p>Chefs locais e nacionais com aulas abertas, degustações e jantar show.</p>
                    <strong>Sabores</strong>
                    <div class="event-extra"><span>O guia cita novembro como referência. Combine com Mercado de Tambaú, orla e restaurantes do entorno.</span></div>
                </article>
            </div>
        </section>

        <section class="event-hall" id="roteiros" aria-labelledby="route-title">
            <div class="event-hall-header">
                <div>
                    <h2 id="route-title">Roteiros para viver Jampa</h2>
                    <p>Ideias de trajetos tiradas do guia para combinar praia, cultura, comida regional, compras e vida noturna sem deixar o dia vazio.</p>
                </div>
            </div>
            <div class="event-grid">
                <article class="event-card">
                    <div class="event-banner cultura" data-label="Maré baixa">
                        <img src="assets/eventos/praia-tambau.jpg" alt="Praia de Tambaú em João Pessoa" loading="lazy">
                    </div>
                    <h3>Litoral e piscinas naturais</h3>
                    <p>Seixas, Bessa, Cabo Branco e Ilha de Areia Vermelha ficam melhores com consulta à tábua de marés.</p>
                    <strong>Praias</strong>
                </article>
                <article class="event-card">
                    <div class="event-banner artesanato" data-label="Centro">
                        <img src="assets/eventos/centro-historico.jpg" alt="Centro Histórico de João Pessoa com vista para o Rio Sanhauá" loading="lazy">
                    </div>
                    <h3>Centro Histórico</h3>
                    <p>Centro Cultural São Francisco, Hotel Globo, Praça Antenor Navarro, bares e música ao vivo.</p>
                    <strong>Cultura</strong>
                </article>
                <article class="event-card">
                    <div class="event-banner festival" data-label="Sabores">
                        <img src="assets/eventos/restaurant-week.jpg" alt="Gastronomia em João Pessoa" loading="lazy">
                    </div>
                    <h3>Gastronomia paraibana</h3>
                    <p>Tábua de Carne, Mangai, NAU, Canoa dos Camarões, feiras e Mercado de Tambaú.</p>
                    <strong>Comida</strong>
                </article>
                <article class="event-card">
                    <div class="event-banner carnaval" data-label="Noite">
                        <img src="assets/eventos/sabadinho.jpg" alt="Público dançando no Sabadinho Bom, em João Pessoa" loading="lazy">
                    </div>
                    <h3>Samba, pubs e orla</h3>
                    <p>Sabadinho Bom, Loca Centro, Vila do Porto, Empório Café e Boteco da Orla entram na agenda noturna.</p>
                    <strong>Música</strong>
                </article>
                <article class="event-card">
                    <div class="event-banner saojoao" data-label="Compras">
                        <img src="assets/eventos/mercado-artesanato.jpeg" alt="Loja no Mercado de Artesanato Paraibano" loading="lazy">
                    </div>
                    <h3>Feiras e lembranças</h3>
                    <p>Mercado de Artesanato Paraibano, Mercado Central, Feira Livre de Jaguaribe e Feira da Orla.</p>
                    <strong>Achados</strong>
                </article>
            </div>
        </section>

    </main>
</div>


<!-- ======================================================
     MODAL DE LOGIN / CADASTRO
====================================================== -->
<div class="modal-overlay" id="modalOverlay" role="dialog" aria-modal="true" aria-labelledby="modalTitle">
    <div class="modal">
        <button class="modal-close" onclick="fecharModal()" aria-label="Fechar">&times;</button>

        <!-- Abas -->
        <div class="modal-tabs">
            <button class="modal-tab active" id="tabLogin"    onclick="trocarAba('login')">Entrar</button>
            <button class="modal-tab"         id="tabRegistro" onclick="trocarAba('registro')">Cadastrar</button>
        </div>

        <!-- Mensagens de erro / sucesso (vindas do PHP) -->
        <?php if ($erro): ?>
            <div class="alert error"><?= htmlspecialchars($erro) ?></div>
        <?php endif; ?>
        <?php if ($sucesso): ?>
            <div class="alert success"><?= htmlspecialchars($sucesso) ?></div>
        <?php endif; ?>

        <!-- ---- Painel: Login ---- -->
        <div class="modal-panel active" id="panelLogin">
            <h2 id="modalTitle">Bem-vindo</h2>
            <p class="sub">Acesse sua conta Conexão Jampa</p>

            <form method="POST" action="index.php">
                <input type="hidden" name="acao" value="login">

                <div class="field">
                    <label for="login_email">E-mail</label>
                    <input type="email" id="login_email" name="email"
                           placeholder="seu@email.com" required
                           value="<?= htmlspecialchars($_POST['email'] ?? '') ?>">
                </div>

                <div class="field">
                    <label for="login_senha">Senha</label>
                    <input type="password" id="login_senha" name="senha"
                           placeholder="••••••••" required>
                </div>

                <button type="submit" class="btn-submit">Entrar</button>
            </form>
        </div>

        <!-- ---- Painel: Cadastro ---- -->
        <div class="modal-panel" id="panelRegistro">
            <h2>Criar conta</h2>
            <p class="sub">Junte-se à comunidade Conexão Jampa</p>

            <form method="POST" action="index.php">
                <input type="hidden" name="acao" value="registrar">

                <div class="field">
                    <label for="reg_nome">Nome</label>
                    <input type="text" id="reg_nome" name="nome"
                           placeholder="Seu nome" required
                           value="<?= htmlspecialchars($_POST['nome'] ?? '') ?>">
                </div>

                <div class="field">
                    <label for="reg_email">E-mail</label>
                    <input type="email" id="reg_email" name="email"
                           placeholder="seu@email.com" required
                           value="<?= htmlspecialchars($_POST['email'] ?? '') ?>">
                </div>

                <div class="field">
                    <label for="reg_senha">Senha</label>
                    <input type="password" id="reg_senha" name="senha"
                           placeholder="Mínimo 6 caracteres" required>
                </div>

                <div class="field">
                    <label for="reg_confirmar">Confirmar senha</label>
                    <input type="password" id="reg_confirmar" name="confirmar"
                           placeholder="Repita a senha" required>
                </div>

                <button type="submit" class="btn-submit">Criar conta</button>
            </form>
        </div>
    </div><!-- /modal -->
</div><!-- /overlay -->


<script>
    // Carrossel
    const track = document.getElementById("track");
    const slides = document.querySelectorAll(".slide");
    const prev = document.getElementById("prev");
    const next = document.getElementById("next");
    let currentSlide = 0;

    function updateCarousel() {
        if (!track || !slides.length) return;
        track.style.transform = `translateX(-${currentSlide * 100}%)`;
    }

    if (track && prev && next && slides.length) {
        prev.addEventListener("click", () => {
            currentSlide = currentSlide === 0 ? slides.length - 1 : currentSlide - 1;
            updateCarousel();
        });

        next.addEventListener("click", () => {
            currentSlide = currentSlide === slides.length - 1 ? 0 : currentSlide + 1;
            updateCarousel();
        });
    }

    document.querySelectorAll('.event-card.has-extra').forEach((card) => {
        card.addEventListener('click', (event) => {
            if (event.target.closest('.favorite-form, .favorite-button')) return;
            card.classList.toggle('is-open');
        });

        card.addEventListener('keydown', (event) => {
            if (event.target.closest('.favorite-form, .favorite-button')) return;
            if (event.key === 'Enter' || event.key === ' ') {
                event.preventDefault();
                card.classList.toggle('is-open');
            }
        });
    });

    const overlay = document.getElementById('modalOverlay');

    function abrirModal(aba = 'login') {
        overlay.classList.add('open');
        trocarAba(aba);
        document.body.style.overflow = 'hidden';
    }

    function fecharModal() {
        overlay.classList.remove('open');
        document.body.style.overflow = '';
    }

    // Fecha ao clicar fora do modal
    overlay.addEventListener('click', function(e) {
        if (e.target === overlay) fecharModal();
    });

    // Fecha com ESC
    document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape') fecharModal();
    });

    function trocarAba(aba) {
        document.getElementById('panelLogin').classList.toggle('active', aba === 'login');
        document.getElementById('panelRegistro').classList.toggle('active', aba === 'registro');
        document.getElementById('tabLogin').classList.toggle('active', aba === 'login');
        document.getElementById('tabRegistro').classList.toggle('active', aba === 'registro');
    }

    // ----------------------------------------------------------
    // Se o PHP retornou um erro/sucesso, abre o modal
    // automaticamente na aba correspondente à ação enviada
    // ----------------------------------------------------------
    <?php if ($erro || $sucesso): ?>
        const acaoEnviada = '<?= htmlspecialchars($_POST['acao'] ?? '', ENT_QUOTES, 'UTF-8') ?>';        
        abrirModal(acaoEnviada === 'registrar' ? 'registro' : 'login');
    <?php endif; ?>
</script>

<footer>
    <div class="site-footer-inner">
        <div class="footer-brand">
            <strong>Conexão Jampa</strong>
            <span>Eventos, roteiros e experiências para viver João Pessoa.</span>
        </div>
        <div class="footer-contact">
            <span>Fale com a gente</span>
            <div class="footer-links">
                <a href="mailto:contato@conexaojampa.com.br">
                    <svg class="footer-icon" viewBox="0 0 24 24" aria-hidden="true">
                        <path d="M4 6h16v12H4z" fill="none" stroke="currentColor" stroke-width="2" stroke-linejoin="round"/>
                        <path d="m4 7 8 6 8-6" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                    </svg>
                    contato@conexaojampa.com.br
                </a>
                <a href="https://www.instagram.com/conexaojampapb/#" target="_blank" rel="noopener noreferrer">
                    <svg class="footer-icon" viewBox="0 0 24 24" aria-hidden="true">
                        <rect x="4" y="4" width="16" height="16" rx="5" fill="none" stroke="currentColor" stroke-width="2"/>
                        <circle cx="12" cy="12" r="3.5" fill="none" stroke="currentColor" stroke-width="2"/>
                        <circle cx="17" cy="7" r="1" fill="currentColor"/>
                    </svg>
                    @conexaojampapb
                </a>
            </div>
        </div>
    </div>
</footer>

</body>
</html>


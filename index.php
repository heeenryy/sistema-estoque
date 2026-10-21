<?php
/**
 * Sistema de Estoque - Frontend (SPA leve).
 * Entrada e saída de mercadorias por digitação OU leitor de código de barras.
 * Requer login. Controle de acesso por perfil (admin/operator).
 */

require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/auth.php';
db_init();

$ver = filemtime(__DIR__ . '/assets/css/style.css') . filemtime(__DIR__ . '/assets/js/app.js');
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Sistema de Estoque</title>
    <link rel="stylesheet" href="assets/css/style.css?v=<?= $ver ?>">
    <script src="vendor/JsBarcode.all.min.js"></script>
</head>
<body>

<!-- ==================== TELA DE LOGIN ==================== -->
<div id="login-screen" class="login-screen hidden">
    <form id="login-form" class="login-box" autocomplete="off">
        <div class="login-logo">S</div>
        <h2>Sistema de Estoque</h2>
        <p class="login-sub">Acesse com seu e-mail/usuário e senha</p>
        <div id="login-error" class="feedback hidden"></div>
        <div class="field">
            <label for="login-identifier">E-mail ou usuário</label>
            <input type="text" id="login-identifier" required autofocus>
        </div>
        <div class="field">
            <label for="login-password">Senha</label>
            <input type="password" id="login-password" required>
        </div>
        <button type="submit" class="btn btn-primary btn-block" id="btn-login">Entrar</button>
    </form>
</div>

<!-- ==================== APLICAÇÃO ==================== -->
<div id="app-shell" class="hidden">
    <header class="topbar">
        <div class="brand">
            <span class="logo">S</span>
            <div>
                <h1>Sistema de Estoque</h1>
                <small>Entrada & Saída por código de barras</small>
            </div>
        </div>
        <div class="topbar-right">
            <div class="user-chip">
                <span class="user-avatar" id="user-avatar">?</span>
                <div class="user-meta">
                    <strong id="user-name"></strong>
                    <small id="user-role"></small>
                </div>
                <button class="btn btn-ghost btn-sm" id="btn-logout">Sair</button>
            </div>
        </div>
    </header>

    <nav class="tabs" id="main-tabs">
        <button class="tab active" data-view="dashboard">Painel</button>
        <button class="tab" data-view="items" data-perm="items.view">Estoque</button>
        <button class="tab" data-view="entry" data-perm="movement.in">Entrada</button>
        <button class="tab" data-view="exit" data-perm="movement.out">Saída</button>
        <button class="tab" data-view="history" data-perm="history.view">Histórico</button>
        <button class="tab" data-view="reports" data-perm="reports.view">Relatórios</button>
        <button class="tab" data-view="labels" data-perm="labels.create">Etiquetas</button>
        <button class="tab" data-view="users" data-perm="users.manage">Usuários</button>
        <button class="tab" data-view="account">Minha conta</button>
    </nav>

    <main id="app">
        <!-- Painel -->
        <section id="view-dashboard" class="view active">
            <div class="cards" id="stat-cards"></div>
            <div class="grid-2">
                <div class="panel">
                    <h3>Estoque baixo</h3>
                    <div id="low-items"></div>
                </div>
                <div class="panel">
                    <h3>Últimas movimentações</h3>
                    <div id="recent-movements"></div>
                </div>
            </div>
        </section>

        <!-- Estoque / Itens -->
        <section id="view-items" class="view">
            <div class="toolbar">
                <div class="toolbar-left">
                    <input type="search" id="item-search" placeholder="Buscar por código, nome ou local...">
                    <select id="item-category-filter">
                        <option value="">Todas as categorias</option>
                    </select>
                    <label class="check"><input type="checkbox" id="item-low-filter"> Só estoque baixo</label>
                </div>
                <button class="btn btn-primary" id="btn-new-item" data-perm="items.create">+ Novo item</button>
            </div>
            <div class="panel table-wrap">
                <table id="items-table">
                    <thead>
                        <tr>
                            <th>Código</th>
                            <th>Nome</th>
                            <th>Categoria</th>
                            <th>Local</th>
                            <th class="num">Qtd.</th>
                            <th class="num">Mín.</th>
                            <th class="num">Preço</th>
                            <th class="num">Valor total</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody></tbody>
                </table>
                <div class="pagination" id="items-pagination"></div>
            </div>
        </section>

        <!-- Entrada -->
        <section id="view-entry" class="view">
            <div class="scan-box">
                <h3>Entrada de mercadorias</h3>
                <p class="hint">Digite o código ou escaneie com o leitor de código de barras e pressione <kbd>Enter</kbd>.</p>
                <div class="scan-row">
                    <input type="text" id="entry-scan" class="scan-input" placeholder="Código de barras..." autocomplete="off">
                    <button class="btn btn-primary" id="btn-entry-add">Adicionar</button>
                </div>
                <label class="check scan-lock"><input type="checkbox" id="entry-lock" checked> Manter campo focado para escanear vários itens</label>
                <div id="entry-feedback"></div>
            </div>
            <div class="panel">
                <div class="panel-head">
                    <h3>Itens para entrada</h3>
                    <div>
                        <input type="text" id="entry-note" placeholder="Observação (opcional)">
                        <button class="btn btn-success" id="btn-entry-confirm">Confirmar entrada</button>
                    </div>
                </div>
                <div class="table-wrap">
                    <table id="entry-table">
                        <thead>
                            <tr><th>Código</th><th>Nome</th><th class="num">Qtd.</th><th></th></tr>
                        </thead>
                        <tbody></tbody>
                    </table>
                </div>
                <div id="entry-list-empty" class="empty">Nenhum item adicionado ainda.</div>
            </div>
        </section>

        <!-- Saída -->
        <section id="view-exit" class="view">
            <div class="scan-box">
                <h3>Saída de mercadorias</h3>
                <p class="hint">Escaneie o código de barras do produto para dar saída. A saída gera um documento com código de barras.</p>
                <div class="scan-row">
                    <input type="text" id="exit-scan" class="scan-input" placeholder="Código de barras..." autocomplete="off">
                    <button class="btn btn-primary" id="btn-exit-add">Adicionar</button>
                </div>
                <label class="check scan-lock"><input type="checkbox" id="exit-lock" checked> Manter campo focado para escanear vários itens</label>
                <div id="exit-feedback"></div>
            </div>
            <div class="panel">
                <div class="panel-head">
                    <h3>Itens para saída</h3>
                    <div>
                        <input type="text" id="exit-note" placeholder="Observação / destino (opcional)">
                        <button class="btn btn-danger" id="btn-exit-confirm">Confirmar saída</button>
                    </div>
                </div>
                <div class="table-wrap">
                    <table id="exit-table">
                        <thead>
                            <tr><th>Código</th><th>Nome</th><th class="num">Qtd.</th><th></th></tr>
                        </thead>
                        <tbody></tbody>
                    </table>
                </div>
                <div id="exit-list-empty" class="empty">Nenhum item adicionado ainda.</div>
            </div>
        </section>

        <!-- Histórico -->
        <section id="view-history" class="view">
            <div class="toolbar">
                <div class="toolbar-left">
                    <select id="history-type">
                        <option value="">Todos</option>
                        <option value="in">Entradas</option>
                        <option value="out">Saídas</option>
                    </select>
                    <input type="search" id="history-search" placeholder="Buscar por código, nome ou documento...">
                    <button class="btn btn-ghost" id="btn-print-history">Imprimir relatório</button>
                </div>
            </div>
            <div class="panel table-wrap" id="history-wrap">
                <table id="history-table">
                    <thead>
                        <tr>
                            <th>Doc.</th>
                            <th>Data/Hora</th>
                            <th>Tipo</th>
                            <th>Código</th>
                            <th>Produto</th>
                            <th class="num">Qtd.</th>
                            <th>Usuário</th>
                            <th>Obs.</th>
                        </tr>
                    </thead>
                    <tbody></tbody>
                </table>
                <div class="pagination" id="history-pagination"></div>
            </div>
        </section>

        <!-- Relatórios -->
        <section id="view-reports" class="view">
            <div class="toolbar reports-filters">
                <input type="date" id="report-from" title="De">
                <input type="date" id="report-to" title="Até">
                <select id="report-type">
                    <option value="">Entradas + Saídas</option>
                    <option value="in">Só entradas</option>
                    <option value="out">Só saídas</option>
                </select>
                <select id="report-user">
                    <option value="">Todos os usuários</option>
                </select>
                <input type="search" id="report-search" placeholder="Produto ou código...">
                <button class="btn btn-primary" id="btn-report-run">Filtrar</button>
            </div>

            <div class="cards" id="report-summary"></div>

            <div class="panel">
                <div class="panel-head">
                    <h3 id="report-title">Movimentações</h3>
                    <div>
                        <button class="btn btn-ghost" id="btn-report-by-item">Por produto</button>
                        <button class="btn btn-ghost" id="btn-report-low">Estoque baixo</button>
                        <button class="btn btn-ghost" id="btn-report-export">Exportar CSV</button>
                        <button class="btn btn-success" id="btn-report-print">Imprimir</button>
                    </div>
                </div>
                <div class="table-wrap" id="report-wrap">
                    <table id="report-table">
                        <thead>
                            <tr>
                                <th>Doc.</th>
                                <th>Data/Hora</th>
                                <th>Tipo</th>
                                <th>Código</th>
                                <th>Produto</th>
                                <th class="num">Qtd.</th>
                                <th>Usuário</th>
                                <th>Obs.</th>
                            </tr>
                        </thead>
                        <tbody></tbody>
                    </table>
                </div>
            </div>
        </section>

        <!-- Etiquetas -->
        <section id="view-labels" class="view">
            <div class="scan-box">
                <h3>Imprimir etiquetas</h3>
                <p class="hint">Informe o código e a quantidade de etiquetas. O código de barras será gerado para impressão.</p>
                <div class="scan-row">
                    <input type="text" id="label-code" placeholder="Código do produto..." autocomplete="off">
                    <input type="number" id="label-qty" value="1" min="1" max="99" class="qty-input">
                    <button class="btn btn-primary" id="btn-label-add">Gerar etiqueta</button>
                </div>
                <div id="label-feedback"></div>
            </div>
            <div class="panel">
                <div class="panel-head">
                    <h3>Etiquetas geradas</h3>
                    <div>
                        <button class="btn btn-ghost" id="btn-label-clear">Limpar</button>
                        <button class="btn btn-success" id="btn-label-print">Imprimir etiquetas</button>
                    </div>
                </div>
                <div id="label-sheet" class="label-sheet"></div>
            </div>
        </section>

        <!-- Usuários (admin) -->
        <section id="view-users" class="view">
            <div class="toolbar">
                <div class="toolbar-left">
                    <h3 style="margin:0">Gerenciar usuários</h3>
                </div>
                <button class="btn btn-primary" id="btn-new-user">+ Novo usuário</button>
            </div>
            <div class="panel table-wrap">
                <table id="users-table">
                    <thead>
                        <tr>
                            <th>Nome</th>
                            <th>E-mail</th>
                            <th>Perfil</th>
                            <th>Status</th>
                            <th>Último acesso</th>
                            <th class="num">Movimentações</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody></tbody>
                </table>
            </div>
        </section>

        <!-- Minha conta -->
        <section id="view-account" class="view">
            <div class="panel" style="max-width:480px">
                <h3>Minha conta</h3>
                <div id="account-info" class="account-info"></div>
            </div>
            <div class="panel" style="max-width:480px">
                <h3>Alterar senha</h3>
                <form id="change-password-form">
                    <div class="field">
                        <label for="cp-current">Senha atual</label>
                        <input type="password" id="cp-current" required>
                    </div>
                    <div class="field">
                        <label for="cp-new">Nova senha</label>
                        <input type="password" id="cp-new" required>
                        <small class="hint">Mínimo de 6 caracteres.</small>
                    </div>
                    <div class="field">
                        <label for="cp-confirm">Confirmar nova senha</label>
                        <input type="password" id="cp-confirm" required>
                    </div>
                    <button type="submit" class="btn btn-primary">Alterar senha</button>
                </form>
            </div>
        </section>
    </main>
</div>

<!-- ==================== MODAIS ==================== -->

<!-- Modal de item -->
<div id="item-modal" class="modal hidden">
    <div class="modal-box">
        <div class="modal-head">
            <h3 id="item-modal-title">Novo item</h3>
            <button class="modal-close" id="btn-close-modal">&times;</button>
        </div>
        <form id="item-form">
            <input type="hidden" id="item-id">
            <div class="form-row">
                <div class="field grow">
                    <label for="item-code">Código / código de barras *</label>
                    <input type="text" id="item-code" required autocomplete="off">
                    <small class="hint">Escaneie ou digite.</small>
                </div>
                <div class="field">
                    <label for="item-unit">Unidade</label>
                    <input type="text" id="item-unit" value="un">
                </div>
            </div>
            <div class="field">
                <label for="item-name">Nome do produto *</label>
                <input type="text" id="item-name" required>
            </div>
            <div class="form-row">
                <div class="field">
                    <label for="item-category">Categoria</label>
                    <input type="text" id="item-category" list="category-list">
                    <datalist id="category-list"></datalist>
                </div>
                <div class="field">
                    <label for="item-location">Local</label>
                    <input type="text" id="item-location">
                </div>
            </div>
            <div class="form-row">
                <div class="field">
                    <label for="item-quantity">Quantidade inicial</label>
                    <input type="number" id="item-quantity" value="0" min="0" step="any">
                </div>
                <div class="field">
                    <label for="item-min-stock">Estoque mínimo</label>
                    <input type="number" id="item-min-stock" value="0" min="0" step="any">
                </div>
                <div class="field">
                    <label for="item-price">Preço (R$)</label>
                    <input type="number" id="item-price" value="0" min="0" step="0.01">
                </div>
            </div>
            <div class="modal-foot">
                <button type="button" class="btn btn-ghost" id="btn-modal-cancel">Cancelar</button>
                <button type="submit" class="btn btn-primary">Salvar</button>
            </div>
        </form>
    </div>
</div>

<!-- Modal de usuário -->
<div id="user-modal" class="modal hidden">
    <div class="modal-box">
        <div class="modal-head">
            <h3 id="user-modal-title">Novo usuário</h3>
            <button class="modal-close" id="btn-close-user-modal">&times;</button>
        </div>
        <form id="user-form">
            <input type="hidden" id="u-id">
            <div class="field">
                <label for="u-name">Nome completo *</label>
                <input type="text" id="u-name" required>
            </div>
            <div class="form-row">
                <div class="field">
                    <label for="u-email">E-mail *</label>
                    <input type="email" id="u-email" required>
                </div>
                <div class="field">
                    <label for="u-username">Usuário (login)</label>
                    <input type="text" id="u-username">
                </div>
            </div>
            <div class="form-row">
                <div class="field">
                    <label for="u-role">Perfil</label>
                    <select id="u-role">
                        <option value="operator">Operador</option>
                        <option value="admin">Administrador</option>
                    </select>
                </div>
                <div class="field">
                    <label for="u-active">Status</label>
                    <select id="u-active">
                        <option value="1">Ativo</option>
                        <option value="0">Desativado</option>
                    </select>
                </div>
            </div>
            <div class="field" id="u-password-field">
                <label for="u-password">Senha inicial *</label>
                <input type="text" id="u-password">
                <small class="hint">Mínimo de 6 caracteres.</small>
            </div>
            <div class="field hidden" id="u-reset-field">
                <label>Redefinir senha</label>
                <button type="button" class="btn btn-ghost" id="btn-user-reset">Gerar nova senha temporária</button>
            </div>
            <div class="modal-foot">
                <button type="button" class="btn btn-ghost" id="btn-user-cancel">Cancelar</button>
                <button type="submit" class="btn btn-primary">Salvar</button>
            </div>
        </form>
    </div>
</div>

<!-- Modal de sucesso com código de barras da movimentação -->
<div id="result-modal" class="modal hidden">
    <div class="modal-box result-box">
        <div class="modal-head">
            <h3 id="result-title">Sucesso!</h3>
            <button class="modal-close" id="btn-close-result">&times;</button>
        </div>
        <div id="result-message" class="result-message"></div>
        <div id="result-barcode" class="result-barcode"></div>
        <p class="hint" id="result-ref"></p>
        <div class="modal-foot">
            <button class="btn btn-ghost" id="btn-result-print">Imprimir</button>
            <button class="btn btn-primary" id="btn-close-result-2">OK</button>
        </div>
    </div>
</div>

<div id="toast" class="toast hidden"></div>

<script src="assets/js/app.js?v=<?= $ver ?>"></script>
</body>
</html>

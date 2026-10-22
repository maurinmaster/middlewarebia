# Diretrizes para Agentes de IA - Middleware Bia

Este documento contém as instruções fundamentais, regras de negócio e convenções arquiteturais que devem ser seguidas por qualquer agente de IA ao atuar no repositório **Middleware Bia** (Integração eGestor PDV ⇄ Nuvemshop).

---

## 🎯 Regras de Negócio Fundamentais (Invioláveis)
1. **Cadastro do eGestor NÃO vai automaticamente para a Nuvemshop:** Novos produtos criados no eGestor ficam apenas no PDV. A publicação na Nuvemshop é sempre sob demanda.
2. **Lista de Produtos em Tempo Real:** A tela principal exibe o status de sincronização real comparando os dados diretamente das APIs.
3. **Cadastros pelo Middleware são Simultâneos:** Produtos novos criados pela aba "Cadastrar Produto" são enviados para ambos os sistemas ao mesmo tempo com os dados fiscais.
4. **Sincronização Bilateral Condicionada:** Vendas no PDV baixam estoque na Nuvemshop e vice-versa, **apenas para produtos que existam na Nuvemshop**. Se o item não existe na Nuvemshop, o webhook deve ignorar de forma limpa (`unmapped_product_skipped`) sem registrar erro.
5. **Zero Armazenamento de Produtos no Banco Local:** O banco SQLite (`data/database.sqlite`) não armazena catálogo de produtos nem tabelas estáticas de mapeamento. Tudo opera em tempo real via SKU. O banco local guarda estritamente logs (máx. 500) e pedidos (máx. 300) com poda automática (`Database::pruneHistory()`).

---

## ⚠️ Gotchas Técnicos Críticos
- **IPv4 Obrigatório no cURL (Windows):** Toda inicialização de cURL para eGestor ou Nuvemshop deve conter `CURLOPT_IPRESOLVE => CURL_IPRESOLVE_V4` e `CURLOPT_CONNECTTIMEOUT => 8` para evitar travamentos de 20 segundos de DNS IPv6.
- **Variações de Grade:** Toda variante unificada na Nuvemshop deve conter no campo `sku` o respectivo código/ID do eGestor. Variações do mesmo anúncio não podem ter valores de atributos idênticos (a Nuvemshop rejeita com erro 422).

---

<!-- ai-memory:start -->
## Long-term memory (ai-memory)

This project uses [ai-memory](https://github.com/akitaonrails/ai-memory)
for cross-session continuity.

**Choose project scope from the MCP client's identity support.**

- **Session-aware MCP clients** that forward the real lifecycle-hook session id
  on every request should use automatic current-project routing. Omit `workspace`,
  `project`, and `cwd` for the current repository; pass explicit scope only when
  the user names a different project.
- **Static MCP clients** (including clients with lifecycle hooks but no bridge
  connecting that hook session id to MCP requests) must pass `workspace` and
  `project` together on every project-scoped call, including requests about "this
  project", "here", or "our work". Read the exact names from the nearest
  `.ai-memory.toml` when it declares both (`workspace = "default"`, `project = "maurinmaster-middlewarebia"`).
  Without a marker override, derive the project from the normalized `upstream` remote, then `origin`,
  using the full repository path without its host (`github.com/acme/api` → `acme-api`); use the
  folder basename only when no valid remote exists. Never rely on the server's
  last active project.

This rule applies only to project-scoped calls. For cross-project retrieval,
`global=true` must omit `workspace`, `project`, and `scopes`. For a standing
preference written with `scope: "global"`, omit `workspace` and `project`.

### Session Start Routine
At the beginning of each session or before starting architectural / sync tasks:
1. Call `memory_briefing` or `memory_query` to load pinned rules, decisions, and gotchas.
2. Respect existing architectural decisions recorded in `decisions/` and `_rules/`.
3. When new critical decisions or rules are established with the user, record them with `memory_write_page` (`pinned: true`).

<!-- ai-memory:end -->

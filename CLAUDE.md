# Regras do projeto Salinas TI (Conference_IT)

- Em todo o escopo deste projeto, use a skill `equipe-llm`: Claude coordena, Codex e Gemini (Antigravity) executam, e o contexto é compartilhado pelo ai-memory.
- Registre o contexto entre sessões com `ai-memory-handoff`.
- Faça commit e envie ao GitHub cada ajuste, em branch própria com pull request para `main`.
- Rode e desenvolva a partir de `C:\dev\Conference_IT`, fora do OneDrive: a sincronização trava o MariaDB em `storage/mariadb`.
- Execução local: XAMPP (PHP 8.2 + MariaDB) em `C:\xampp`; `INICIAR.bat` sobe a aplicação em http://localhost:2288 e o banco isolado na porta 3308.
- Credenciais, `.env`, `storage/` e `ACESSO-*.txt` nunca vão para o Git.

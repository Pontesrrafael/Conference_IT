# Requisitantes nas consultas Minerion

Em **Integração Minerion → Parâmetros da consulta — requisitantes SGA**, informe o ID do funcionário (FUNCOD) e clique em **Adicionar requisitante**. A aplicação consulta o nome no Minerion, valida a existência do cadastro e salva o ID para as próximas consultas de compras e patrimônios.

Depois, use **Consultar SGA e sincronizar agora** para importar os registros do escopo atualizado. Adicionar um ID não inicia a carga automaticamente.

- Apenas administradores podem alterar os parâmetros; o formulário usa CSRF e registra auditoria.
- Use **Remover da consulta** ao lado de cada ID para excluí-lo das próximas consultas, inclusive os IDs iniciais 756, 772, 316 e 1318. Mantenha ao menos um ID; é possível adicionar novamente um ID removido.
- A remoção preserva o histórico local, compras e patrimônios importados. Registros fora do escopo não são marcados como ausentes na origem pela próxima sincronização. Não exclui funcionários no Minerion nem usuários de acesso à aplicação.
- As telas **Painel**, **Patrimônios** e **Compras** mostram por padrão só o escopo atual (requisitantes e centros de custo configurados, de qualquer categoria). O histórico de IDs removidos continua disponível em **Todo o histórico importado**.
- O limite é de 200 IDs. Duplicados não são adicionados; IDs inexistentes e entradas não numéricas são recusados.
- A lista fica em `storage/requisitantes.json`, arquivo privado excluído do Git. Inclua-o no backup da instalação.
- Novos requisitantes são cadastrados na tabela local durante a importação para manter os vínculos das compras.
- Coletas pontuais por RC/processo continuam abrangendo todos os itens do documento.
- Os arquivos SQL usam o marcador `{{REQUISITANTES_SGA}}`, preenchido apenas com inteiros validados pelo serviço. Não executar o template cru no SQL Server.

Validação portátil: `php bin/verificar-requisitantes.php`. O teste usa armazenamento temporário e funcionários sintéticos, sem acessar o banco operacional.
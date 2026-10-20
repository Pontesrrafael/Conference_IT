# Identidade visual — Salinas Gold

Aplicada à instalação D:/Apps_SG/Est_Sg, porta 2288.

Referência: Manual da Marca - Salinas Gold Mineração.pdf fornecido pelo usuário. Foram lidas as 14 páginas e conferidas visualmente as páginas 11 (cores/tipografia), 12 (aplicações) e 13 (usos proibidos).

- Cores institucionais: #024B37 (verde), #FFCD00 (amarelo), #C6D53E (apoio), #707070 (cinza) e #FFFFFF (branco). Para a especificação de cor foram priorizados os valores hexadecimais do manual, inclusive onde a amostra ou os números RGB divergem.
- Fonte Author, em arquivos WOFF2 originais fornecidos, pesos Regular, Medium, Bold e Light, hospedados localmente. Licença original preservada em public/assets/fonts/LICENSE-Author.txt.
- Logotipo horizontal original logo 1 - Hor .png, preservado integralmente e aplicado sobre placa branca no menu, login e mensagem de erro. Sem filtros, recortes, distorção, recomposição ou alteração de cor do logotipo.
- Nome do módulo separado da assinatura oficial. Espaço de respiro em torno do logotipo. Versões branca e vertical originais também preservadas em assets/brand para futuros usos adequados.
- Verde e amarelo nos destaques, ações e seleção de navegação; verde claro como apoio. Textos de interface usam cores com contraste, sem usar amarelo para texto sobre branco.
- Modo escuro acompanha o sistema e conserva as cores da marca, com fundos auxiliares escuros. A placa branca do logotipo garante a legibilidade em ambos os temas.
- Layout responsivo mantido; o menu horizontal tem rolagem nas telas estreitas, com acesso à conta e saída visíveis.
- Atualização da rota de arquivos estáticos limitada a CSS, PNG e WOFF2 dentro de public/assets; segredos e arquivos de armazenamento continuam fora do DocumentRoot.

Validação: PHP sem erros de sintaxe nos templates modificados e no roteador; CSS, logo e fonte responderam HTTP 200 com tipos corretos; página de saúde respondeu HTTP 200; painel renderizado e inspecionado no navegador. Não houve alteração de configuração SGA nem de dados do inventário nesta atualização.

Os templates anteriores estão guardados em storage/backups/identidade-<data-hora>.

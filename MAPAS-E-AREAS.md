# Mapas e áreas

Acesse http://localhost:2288/mapas. Operadores de TI e administradores podem enviar TIFF/GeoTIFF com projeção e transformação embutidas, até 256 MiB; os demais perfis consultam.

1. Informe nome, unidade e imagem. O arquivo original permanece privado em `storage/mapas`; a visualização é uma prévia PNG de até 2.400 pixels, reprojetada para WGS84. Não depende de mapas externos nem envia a imagem a terceiros.
2. Abra o mapa, selecione **Desenhar área**, marque 3 a 100 vértices e salve nome e descrição. Polígonos que se cruzam são recusados. Use Navegar, zoom ou teclado (setas e Enter) para trabalhar no mapa.
3. Pesquise patrimônios ou compras. Escolha a área e vincule cada item; ou escolha **Projeto inteiro** para visualizar dinamicamente suas compras e percentuais atuais.
4. Clique nas áreas para ver os vínculos. O contorno pode ser editado. Vínculos podem ser retirados e incluídos novamente, com auditoria.

Cada patrimônio possui uma área corrente, substituída em um novo apontamento. Isso é um registro cartográfico e não altera situação física, local, centro de custo ou estoque. Compras e projetos podem abranger várias áreas. Seus custos são referências gerenciais e não são rateados geograficamente. Não some custos entre áreas nem some patrimônio com projeto: podem representar a mesma aquisição. O custo dos patrimônios apontados inclui baixados e informa custos ausentes; o custo de projetos usa todo o histórico e acompanha os rateios existentes. Vincular projeto não cria patrimônios fictícios nem movimenta estoque.

GeoJSON exportado usa longitude/latitude WGS84 (EPSG:4326). Projeção, transformação, dimensões e original são preservados. A prévia usa RGB quando identificado, senão as três primeiras bandas ou cinza. TIFFs não byte usam contraste por percentis 2–98 apenas na prévia; não é ferramenta de análise radiométrica. Não são aceitos TIFFs sem georreferenciamento embutido, sidecars TFW/PRJ, apenas GCP/RPC, arquivos atravessando antimeridiano ou uploads acima do limite. Para esses casos, exporte um GeoTIFF com projeção e transformação afim no software de geoprocessamento.

Leitor local Python com Rasterio 1.4.4 e Pillow em `storage/geo-runtime`. `MAP_PYTHON` pode informar outro executável Python; o padrão aponta para o Python já instalado nesta máquina. Os scripts de inicialização configuram upload em 256M e POST em 270M. Reinicie o servidor depois de alterar esses limites. Os TIFFs e PNGs só são servidos após login. Backup do banco deve ser acompanhado de backup de `storage/mapas` para preservar as imagens.

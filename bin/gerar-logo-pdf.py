"""Gera a cópia do logotipo usada no PDF a partir do arquivo oficial (public/assets/brand/salinas-gold-horizontal.png).
O logotipo é aplicado sem alteração de cor sobre fundo branco (a mesma placa branca usada na interface) e reduzido
para 1200 px de largura. Saída: PNG RGB 8 bits, não entrelaçado, que o PDF incorpora sem decodificar (FlateDecode + preditor PNG).
Uso: python bin/gerar-logo-pdf.py"""
from pathlib import Path
from PIL import Image
raiz = Path(__file__).resolve().parents[1]
origem = Image.open(raiz / 'public/assets/brand/salinas-gold-horizontal.png').convert('RGBA')
placa = Image.new('RGBA', origem.size, (255, 255, 255, 255))
placa.alpha_composite(origem)
largura = 1200
logo = placa.convert('RGB').resize((largura, round(origem.height * largura / origem.width)), Image.LANCZOS)
destino = raiz / 'app/Assets/logo-salinas-gold-pdf.png'
logo.save(destino, 'PNG', optimize=True)
print(destino, logo.size)

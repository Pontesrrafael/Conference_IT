"""Gera as imagens usadas no PDF a partir dos arquivos oficiais da marca (timbrado padrão Salinas Gold).
- app/Assets/logo-salinas-gold-pdf.png: logotipo horizontal (public/assets/brand/salinas-gold-horizontal.png),
  sem alteração de cor, sobre fundo branco, com 1200 px de largura.
- app/Assets/pin-endereco-pdf.png: marcador de endereço do rodapé do timbrado (public/assets/brand/pin-endereco.png,
  extraído do MODELO - TIMBRADO.docx) sobre o quadrado amarelo arredondado #FFCD00, como no documento padrão.
Saída: PNG RGB 8 bits, não entrelaçado, que o PDF incorpora sem decodificar (FlateDecode + preditor PNG).
Uso: python bin/gerar-logo-pdf.py"""
from pathlib import Path
from PIL import Image, ImageDraw
raiz = Path(__file__).resolve().parents[1]

origem = Image.open(raiz / 'public/assets/brand/salinas-gold-horizontal.png').convert('RGBA')
placa = Image.new('RGBA', origem.size, (255, 255, 255, 255))
placa.alpha_composite(origem)
largura = 1200
logo = placa.convert('RGB').resize((largura, round(origem.height * largura / origem.width)), Image.LANCZOS)
destino = raiz / 'app/Assets/logo-salinas-gold-pdf.png'
logo.save(destino, 'PNG', optimize=True)
print(destino, logo.size)

# Marcador: desenhado em 4x e reduzido (bordas suaves), fundo branco fora do quadrado.
lado, escala = 96, 4
grande = Image.new('RGBA', (lado * escala, lado * escala), (255, 255, 255, 255))
ImageDraw.Draw(grande).rounded_rectangle((0, 0, lado * escala - 1, lado * escala - 1), radius=22 * escala, fill=(255, 205, 0, 255))
pin = Image.open(raiz / 'public/assets/brand/pin-endereco.png').convert('RGBA')
caixa = pin.getbbox()
pin = pin.crop(caixa)
alt = round(lado * escala * 0.62)
pin = pin.resize((round(pin.width * alt / pin.height), alt), Image.LANCZOS)
grande.alpha_composite(pin, ((grande.width - pin.width) // 2, (grande.height - pin.height) // 2))
marcador = grande.convert('RGB').resize((lado, lado), Image.LANCZOS)
destino = raiz / 'app/Assets/pin-endereco-pdf.png'
marcador.save(destino, 'PNG', optimize=True)
print(destino, marcador.size)

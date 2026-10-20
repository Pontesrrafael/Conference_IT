"""Private GeoTIFF preview generator. No network or external sidecars are used."""
import json, math, sys, warnings
from pathlib import Path
sys.path.insert(0, str(Path(__file__).resolve().parents[1] / 'storage' / 'geo-runtime'))
import numpy as np
import rasterio
from rasterio.enums import Resampling, ColorInterp
from rasterio.vrt import WarpedVRT
from PIL import Image

def convert(source, target):
    # Restrict GDAL to the uploaded TIFF, including file lists and network virtual paths.
    with rasterio.Env(GDAL_DISABLE_READDIR_ON_OPEN='EMPTY_DIR', GDAL_CACHEMAX=64*1024*1024, GDAL_PAM_ENABLED='NO', PROJ_NETWORK='OFF'):
        with rasterio.open(source, driver='GTiff') as src:
            if src.driver != 'GTiff' or not src.crs or src.transform.is_identity:
                raise ValueError('O TIFF precisa conter projeção e georreferenciamento (GeoTIFF). Exporte a imagem como GeoTIFF no seu sistema de mapas.')
            if src.width < 2 or src.height < 2 or src.count < 1:
                raise ValueError('Imagem TIFF sem dimensões ou bandas válidas.')
            with WarpedVRT(src, crs='EPSG:4326', resampling=Resampling.bilinear, warp_mem_limit=64) as vrt:
                bounds=list(vrt.bounds)
                if not all(math.isfinite(x) for x in bounds) or not (-180<=bounds[0]<bounds[2]<=180 and -90<=bounds[1]<bounds[3]<=90):
                    raise ValueError('Limites geográficos inválidos ou imagem atravessando o antimeridiano.')
                ratio=min(1,2400/max(vrt.width,vrt.height))
                w,h=max(1,round(vrt.width*ratio)),max(1,round(vrt.height*ratio))
                colors=list(src.colorinterp)
                if ColorInterp.red in colors and ColorInterp.green in colors and ColorInterp.blue in colors:
                    bands=[colors.index(x)+1 for x in (ColorInterp.red,ColorInterp.green,ColorInterp.blue)]
                else:
                    bands=[1,2,3] if vrt.count>=3 else [1]
                arr=vrt.read(bands,out_shape=(len(bands),h,w),masked=True,resampling=Resampling.bilinear)
                rgb=[]
                for band in arr:
                    valid=band.compressed(); valid=valid[np.isfinite(valid)]
                    if not valid.size: rgb.append(np.zeros((h,w),dtype='uint8'));continue
                    lo,hi=(0,255) if band.dtype=='uint8' else np.percentile(valid,[2,98])
                    if hi<=lo: hi=lo+1
                    rgb.append(np.nan_to_num(np.clip((band.filled(0).astype('float64')-lo)*255/(hi-lo),0,255)).astype('uint8'))
                if len(rgb)==1: rgb=rgb*3
                if colors and colors[0]==ColorInterp.palette:
                    values=vrt.read(1,out_shape=(h,w),resampling=Resampling.nearest)
                    palette=src.colormap(1); table=np.zeros((65536,3),dtype='uint8')
                    for key,value in palette.items():
                        if 0<=key<65536: table[key]=value[:3]
                    rgb=list(np.moveaxis(table[np.clip(values,0,65535).astype('uint16')],-1,0))
                alpha=vrt.dataset_mask(out_shape=(h,w),resampling=Resampling.nearest)
                Image.fromarray(np.dstack([*rgb,alpha]),'RGBA').save(target,'PNG')
                return dict(crs_original=src.crs.to_string(),transform_original=list(src.transform)[:6],largura_original=src.width,altura_original=src.height,bandas=src.count,largura=w,altura=h,bounds=bounds,crs_visualizacao='EPSG:4326',tratamento='RGB ou cinza; bandas não byte ajustadas por percentis 2–98')

if __name__=='__main__':
    try:
        result=convert(sys.argv[1],sys.argv[2])
        print(json.dumps(result,ensure_ascii=True,allow_nan=False))
    except Exception as exc:
        print(json.dumps({'erro':str(exc)},ensure_ascii=True))
        sys.exit(1)

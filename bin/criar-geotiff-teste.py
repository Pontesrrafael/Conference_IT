"""Synthetic georeferenced fixtures, never imagery of a real Salinas site."""
import sys
from pathlib import Path
sys.path.insert(0,str(Path(__file__).resolve().parents[1]/'storage'/'geo-runtime'))
import numpy as np
import rasterio
from rasterio.transform import from_bounds
root=Path(__file__).resolve().parents[1]/'evidencias'
y,x=np.mgrid[:360,:640]
image=np.stack([(x*255/640).astype('uint8'),(y*255/360).astype('uint8'),np.full((360,640),80,dtype='uint8')])
for name,crs,bounds in [('geotiff-teste-wgs84.tif','EPSG:4326',(-56.10,-15.65,-56.09,-15.64)),('geotiff-teste-utm.tif','EPSG:31981',(598000,8269000,598640,8269360))]:
    with rasterio.open(root/name,'w',driver='GTiff',height=360,width=640,count=3,dtype='uint8',crs=crs,transform=from_bounds(*bounds,640,360),compress='deflate') as dst:
        dst.write(image)
with rasterio.open(root/'tiff-sem-geo-teste.tif','w',driver='GTiff',height=360,width=640,count=3,dtype='uint8') as dst: dst.write(image)
print('Fixtures GeoTIFF WGS84, SIRGAS/UTM e TIFF sem coordenadas criadas.')

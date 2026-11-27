<?php
namespace App\Services;
use App\Models\CoverFingerprint;

class CoverImages {
    public function __construct(private StoreClient $client,private PerceptualHash $hash) {}
    public function cached(string $url): ?CoverFingerprint {
        return CoverFingerprint::where('url_hash',hash('sha256',$url))->where('algorithm',PerceptualHash::VERSION)
            ->where('checked_at','>=',now()->subDays(7))->first();
    }
    public function fetch(string $url): CoverFingerprint {
        if ($cached=$this->cached($url)) return $cached;
        $cover=CoverFingerprint::firstOrNew(['url_hash'=>hash('sha256',$url)]);
        $cover->fill(['url'=>$url,'algorithm'=>PerceptualHash::VERSION,'checked_at'=>now(),'phash'=>null,'content_hash'=>null,'error'=>null]);
        try {
            $bytes=$this->client->imageBytes($url);$data=$this->hash->fromBytes($bytes);
            try {
                $thumb=imagecreatetruecolor(300,300);imagefill($thumb,0,0,imagecolorallocate($thumb,255,255,255));
                $ratio=min(300/$data['width'],300/$data['height']);$w=(int)round($data['width']*$ratio);$h=(int)round($data['height']*$ratio);
                imagecopyresampled($thumb,$data['image'],(int)((300-$w)/2),(int)((300-$h)/2),0,0,$w,$h,$data['width'],$data['height']);
                if (!is_dir(dirname($cover->thumbnailPath()))) mkdir(dirname($cover->thumbnailPath()),0775,true);
                if (!imagejpeg($thumb,$cover->thumbnailPath(),85))throw new \RuntimeException('No se pudo guardar la miniatura.');
                imagedestroy($thumb);
            } finally { imagedestroy($data['image']); }
            $cover->fill(['status'=>'ready','phash'=>$data['phash'],'content_hash'=>hash('sha256',$bytes),'width'=>$data['width'],'height'=>$data['height']]);
        } catch(\Throwable $e) { $cover->fill(['status'=>'failed','error'=>mb_substr($e->getMessage(),0,500)]); }
        $cover->save();return $cover;
    }
}

<?php
namespace App\Services;

class PerceptualHash
{
    public const VERSION='phash-dct32-v1';
    public function fromBytes(string $bytes): array {
        if (!extension_loaded('gd')) throw new \RuntimeException('Se requiere GD para comparar portadas.');
        if (strlen($bytes)>5*1024*1024) throw new \RuntimeException('La portada supera 5 MB.');
        $info=@getimagesizefromstring($bytes);
        if (!$info || !in_array($info[2],[IMAGETYPE_JPEG,IMAGETYPE_PNG,IMAGETYPE_WEBP])) throw new \RuntimeException('Formato de imagen no admitido.');
        [$width,$height]=$info;
        if ($width<64 || $height<64 || $width>6000 || $height>6000 || $width*$height>16000000) throw new \RuntimeException('Dimensiones de portada no admitidas (64–6000 px, máximo 16 MP).');
        $original=@imagecreatefromstring($bytes);if (!$original) throw new \RuntimeException('No se pudo decodificar la portada.');
        $small=imagecreatetruecolor(32,32);imagefill($small,0,0,imagecolorallocate($small,255,255,255));
        imagecopyresampled($small,$original,0,0,0,0,32,32,$width,$height);
        $pixels=[];$sum=0;$squares=0;
        for($y=0;$y<32;$y++)for($x=0;$x<32;$x++) {
            $rgb=imagecolorat($small,$x,$y);$luma=0.299*(($rgb>>16)&255)+0.587*(($rgb>>8)&255)+0.114*($rgb&255);
            $pixels[$y][$x]=$luma;$sum+=$luma;$squares+=$luma*$luma;
        }
        imagedestroy($small);
        if ($squares/1024-($sum/1024)**2<25) { imagedestroy($original);throw new \RuntimeException('Imagen uniforme: no aporta una portada distinguible.'); }
        $cos=[];for($u=0;$u<8;$u++)for($x=0;$x<32;$x++)$cos[$u][$x]=cos((2*$x+1)*$u*pi()/64);
        $row=[];for($y=0;$y<32;$y++)for($u=0;$u<8;$u++) {
            $row[$y][$u]=0;for($x=0;$x<32;$x++)$row[$y][$u]+=$pixels[$y][$x]*$cos[$u][$x];
        }
        $coeff=[];for($v=0;$v<8;$v++)for($u=0;$u<8;$u++) {
            $value=0;for($y=0;$y<32;$y++)$value+=$row[$y][$u]*$cos[$v][$y];
            $coeff[]=$value*($u===0?1/sqrt(2):1)*($v===0?1/sqrt(2):1);
        }
        $ac=array_slice($coeff,1);sort($ac,SORT_NUMERIC);$median=$ac[31];$bits='0';
        foreach(array_slice($coeff,1) as $value)$bits.=$value>$median?'1':'0';
        $hex='';for($i=0;$i<64;$i+=4)$hex.=dechex(bindec(substr($bits,$i,4)));
        return ['phash'=>$hex,'width'=>$width,'height'=>$height,'image'=>$original];
    }
    public function distance(string $a,string $b): int {
        if (!preg_match('/^[0-9a-f]{16}$/i',$a) || !preg_match('/^[0-9a-f]{16}$/i',$b)) throw new \InvalidArgumentException('Huella pHash inválida.');
        $count=0;for($i=0;$i<16;$i++)$count+=substr_count(decbin(hexdec($a[$i])^hexdec($b[$i])),'1');return $count;
    }
}

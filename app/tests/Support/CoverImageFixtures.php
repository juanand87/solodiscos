<?php
namespace Tests\Support;
trait CoverImageFixtures {
    private function coverBytes(bool $alternate=false,int $size=128,bool $jpeg=false): string {
        $image=imagecreatetruecolor($size,$size);
        for($y=0;$y<$size;$y++)for($x=0;$x<$size;$x++){
            $v=$alternate?((int)(($x+$y)/16)%2?220:30):(int)(255*$x/max(1,$size-1));
            imagesetpixel($image,$x,$y,imagecolorallocate($image,$v,(int)($v*.7),255-$v));
        }
        imagefilledellipse($image,(int)($size*($alternate?.2:.65)),(int)($size*($alternate?.8:.35)),(int)($size*.4),(int)($size*.5),imagecolorallocate($image,230,190,100));
        ob_start();if($jpeg)imagejpeg($image,null,82);else imagepng($image);$bytes=ob_get_clean();imagedestroy($image);return $bytes;
    }
}

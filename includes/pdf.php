<?php
/**
 * Hafif, bağımsız PDF üreticisi (harici kütüphane gerektirmez).
 *
 * - A4 sayfa, milimetre koordinatları (sol üst köşe başlangıç)
 * - PDF standart Helvetica / Helvetica-Bold fontları
 * - Türkçe karakterler (ğ Ğ ı İ ş Ş ç Ç ö Ö ü Ü) özel kodlama tablosuyla desteklenir
 * - Metin, dikdörtgen (yuvarlak köşeli), çizgi, döndürme, sayfa ekleme
 */
class Pdf
{
    private const K = 72 / 25.4;          // mm → pt
    public float $w = 210, $h = 297;     // A4 (mm)

    private array $sayfalar = [];
    private int $aktif = -1;
    private string $font = 'F1';
    private float $boyut = 10;
    public string $baslik = 'Belge';

    /** Helvetica glif genişlikleri (1/1000 em), WinAnsi sırasıyla */
    private const GEN = [
        'F1' => [
            278, 278, 278, 278, 278, 278, 278, 278, 278, 278, 278, 278, 278, 278, 278, 278, 278, 278, 278, 278, 278, 278, 278, 278, 278, 278, 278, 278, 278, 278, 278, 278,
            278, 278, 355, 556, 556, 889, 667, 191, 333, 333, 389, 584, 278, 333, 278, 278, 556, 556, 556, 556, 556, 556, 556, 556, 556, 556, 278, 278, 584, 584, 584, 556,
            1015, 667, 667, 722, 722, 667, 611, 778, 722, 278, 500, 667, 556, 833, 722, 778, 667, 778, 722, 667, 611, 722, 667, 944, 667, 667, 611, 278, 278, 278, 469, 556,
            333, 556, 556, 500, 556, 556, 278, 556, 556, 222, 222, 500, 222, 833, 556, 556, 556, 556, 333, 500, 278, 556, 500, 722, 500, 500, 500, 334, 260, 334, 584, 350,
            556, 350, 222, 556, 333, 1000, 556, 556, 333, 1000, 667, 333, 1000, 350, 611, 350, 350, 222, 222, 333, 333, 350, 556, 1000, 333, 1000, 500, 333, 944, 350, 500, 667,
            278, 333, 556, 556, 556, 556, 260, 556, 333, 737, 370, 556, 584, 333, 737, 333, 400, 584, 333, 333, 333, 556, 537, 278, 333, 333, 365, 556, 834, 834, 834, 611,
            667, 667, 667, 667, 667, 667, 1000, 722, 667, 667, 667, 667, 278, 278, 278, 278, 722, 722, 778, 778, 778, 778, 778, 584, 778, 722, 722, 722, 722, 667, 667, 611,
            556, 556, 556, 556, 556, 556, 889, 500, 556, 556, 556, 556, 278, 278, 278, 278, 556, 556, 556, 556, 556, 556, 556, 584, 611, 556, 556, 556, 556, 500, 556, 500,
        ],
        'F2' => [
            278, 278, 278, 278, 278, 278, 278, 278, 278, 278, 278, 278, 278, 278, 278, 278, 278, 278, 278, 278, 278, 278, 278, 278, 278, 278, 278, 278, 278, 278, 278, 278,
            278, 333, 474, 556, 556, 889, 722, 238, 333, 333, 389, 584, 278, 333, 278, 278, 556, 556, 556, 556, 556, 556, 556, 556, 556, 556, 333, 333, 584, 584, 584, 611,
            975, 722, 722, 722, 722, 667, 611, 778, 722, 278, 556, 722, 611, 833, 722, 778, 667, 778, 722, 667, 611, 722, 667, 944, 667, 667, 611, 333, 278, 333, 584, 556,
            333, 556, 611, 556, 611, 556, 333, 611, 611, 278, 278, 556, 278, 889, 611, 611, 611, 611, 389, 556, 333, 611, 556, 778, 556, 556, 500, 389, 280, 389, 584, 350,
            556, 350, 278, 556, 500, 1000, 556, 556, 333, 1000, 667, 333, 1000, 350, 611, 350, 350, 278, 278, 500, 500, 350, 556, 1000, 333, 1000, 556, 333, 944, 350, 500, 667,
            278, 333, 556, 556, 556, 556, 280, 556, 333, 737, 370, 556, 584, 333, 737, 333, 400, 584, 333, 333, 333, 611, 556, 278, 333, 333, 365, 556, 834, 834, 834, 611,
            722, 722, 722, 722, 722, 722, 1000, 722, 667, 667, 667, 667, 278, 278, 278, 278, 722, 722, 778, 778, 778, 778, 778, 584, 778, 722, 722, 722, 722, 667, 667, 611,
            556, 556, 556, 556, 556, 556, 889, 556, 556, 556, 556, 556, 278, 278, 278, 278, 611, 611, 611, 611, 611, 611, 611, 584, 611, 611, 611, 611, 611, 556, 611, 556,
        ],
    ];

    /** WinAnsi yerine Türkçe glif yerleştirilen kodlar ve genişlikleri [normal, kalın] */
    private const TR = [
        208 => ['Gbreve', 778, 778], 221 => ['Idotaccent', 278, 278], 222 => ['Scedilla', 667, 667],
        240 => ['gbreve', 556, 611], 253 => ['dotlessi', 278, 278], 254 => ['scedilla', 500, 556],
    ];
    private const HARITA = [
        'Ğ' => 208, 'İ' => 221, 'Ş' => 222, 'ğ' => 240, 'ı' => 253, 'ş' => 254,
        '€' => 128, '‚' => 130, '„' => 132, '…' => 133, '‘' => 145, '’' => 146, '“' => 147, '”' => 148,
        '•' => 149, '–' => 150, '—' => 151, '™' => 153,
    ];

    public function __construct()
    {
        $this->sayfaEkle();
    }

    public function sayfaEkle(): void
    {
        $this->sayfalar[] = '';
        $this->aktif = count($this->sayfalar) - 1;
    }

    private function yaz(string $s): void
    {
        $this->sayfalar[$this->aktif] .= $s . "\n";
    }

    private static function n(float $v): string
    {
        return rtrim(rtrim(number_format($v, 3, '.', ''), '0'), '.');
    }

    private function x(float $mm): string { return self::n($mm * self::K); }
    private function y(float $mm): string { return self::n(($this->h - $mm) * self::K); }

    /** UTF-8 → PDF tek bayt kodlaması */
    public static function kodla(string $s): string
    {
        $out = '';
        foreach (mb_str_split(str_replace('₺', 'TL', $s)) as $c) {
            if (isset(self::HARITA[$c])) { $out .= chr(self::HARITA[$c]); continue; }
            $o = mb_ord($c);
            if ($o < 128) $out .= $c;
            elseif ($o >= 160 && $o <= 255 && !isset(self::TR[$o])) $out .= chr($o);
            else $out .= '?';
        }
        return $out;
    }

    /** Metin genişliği (mm) */
    public function genislik(string $s, ?float $boyut = null, ?bool $kalin = null): float
    {
        $f = $kalin === null ? $this->font : ($kalin ? 'F2' : 'F1');
        $b = $boyut ?? $this->boyut;
        $t = 0;
        foreach (str_split(self::kodla($s)) as $ch) {
            $o = ord($ch);
            $t += isset(self::TR[$o]) ? self::TR[$o][$f === 'F2' ? 2 : 1] : self::GEN[$f][$o];
        }
        return $t * $b / 1000 / self::K;
    }

    public function font(bool $kalin = false, float $boyut = 10): void
    {
        $this->font = $kalin ? 'F2' : 'F1';
        $this->boyut = $boyut;
    }

    public function dolguRenk(array $rgb): void
    {
        $this->yaz(implode(' ', array_map(fn($v) => self::n($v / 255), $rgb)) . ' rg');
    }

    public function cizgiRenk(array $rgb): void
    {
        $this->yaz(implode(' ', array_map(fn($v) => self::n($v / 255), $rgb)) . ' RG');
    }

    public function kalinlik(float $mm): void
    {
        $this->yaz(self::n($mm * self::K) . ' w');
    }

    /**
     * Metin yazar. $y taban çizgisidir (mm). $hiza: L | C | R ($x, C için orta, R için sağ kenar).
     */
    public function yazi(float $x, float $y, string $metin, array $renk = [15, 23, 42], string $hiza = 'L'): void
    {
        if ($metin === '') return;
        if ($hiza === 'R') $x -= $this->genislik($metin);
        elseif ($hiza === 'C') $x -= $this->genislik($metin) / 2;
        $s = strtr(self::kodla($metin), ['\\' => '\\\\', '(' => '\\(', ')' => '\\)', "\r" => '', "\n" => ' ']);
        $this->yaz('BT ' . implode(' ', array_map(fn($v) => self::n($v / 255), $renk)) . ' rg /' . $this->font . ' ' . self::n($this->boyut)
            . ' Tf 1 0 0 1 ' . $this->x($x) . ' ' . $this->y($y) . ' Tm (' . $s . ') Tj ET');
    }

    /** Metni verilen genişliğe göre satırlara böler */
    public function satirlar(string $metin, float $maxW): array
    {
        $satirlar = [];
        foreach (preg_split('/\R/u', $metin) as $par) {
            $satir = '';
            foreach (preg_split('/\s+/u', trim($par)) as $kelime) {
                $aday = $satir === '' ? $kelime : $satir . ' ' . $kelime;
                if ($satir !== '' && $this->genislik($aday) > $maxW) { $satirlar[] = $satir; $satir = $kelime; }
                else $satir = $aday;
            }
            $satirlar[] = $satir;
        }
        return $satirlar;
    }

    /** Çok satırlı metin; kullanılan son taban çizgisini döner */
    public function paragraf(float $x, float $y, string $metin, float $maxW, float $satirAra, array $renk = [15, 23, 42]): float
    {
        foreach ($this->satirlar($metin, $maxW) as $i => $s) $this->yazi($x, $y + $i * $satirAra, $s, $renk);
        return $y + (count($this->satirlar($metin, $maxW)) - 1) * $satirAra;
    }

    /** Dikdörtgen: $mod F=dolu, D=çerçeve, FD=ikisi. $r>0 yuvarlak köşe (mm) */
    public function kutu(float $x, float $y, float $w, float $h, string $mod = 'F', float $r = 0): void
    {
        $op = ['F' => 'f', 'D' => 'S', 'FD' => 'B'][$mod] ?? 'f';
        if ($r <= 0) {
            $this->yaz($this->x($x) . ' ' . $this->y($y + $h) . ' ' . self::n($w * self::K) . ' ' . self::n($h * self::K) . ' re ' . $op);
            return;
        }
        $k = self::K; $m = 0.5523 * $r;
        $p = fn($px, $py) => self::n($px * $k) . ' ' . self::n(($this->h - $py) * $k);
        $s = $p($x + $r, $y) . ' m ';
        $s .= $p($x + $w - $r, $y) . ' l ' . $p($x + $w - $r + $m, $y) . ' ' . $p($x + $w, $y + $r - $m) . ' ' . $p($x + $w, $y + $r) . ' c ';
        $s .= $p($x + $w, $y + $h - $r) . ' l ' . $p($x + $w, $y + $h - $r + $m) . ' ' . $p($x + $w - $r + $m, $y + $h) . ' ' . $p($x + $w - $r, $y + $h) . ' c ';
        $s .= $p($x + $r, $y + $h) . ' l ' . $p($x + $r - $m, $y + $h) . ' ' . $p($x, $y + $h - $r + $m) . ' ' . $p($x, $y + $h - $r) . ' c ';
        $s .= $p($x, $y + $r) . ' l ' . $p($x, $y + $r - $m) . ' ' . $p($x + $r - $m, $y) . ' ' . $p($x + $r, $y) . ' c ';
        $this->yaz($s . $op);
    }

    public function cizgi(float $x1, float $y1, float $x2, float $y2): void
    {
        $this->yaz($this->x($x1) . ' ' . $this->y($y1) . ' m ' . $this->x($x2) . ' ' . $this->y($y2) . ' l S');
    }

    /** $cx,$cy merkezli $derece döndürülmüş çizim (saat yönünün tersi pozitif) */
    public function dondur(float $derece, float $cx, float $cy, callable $ciz): void
    {
        $a = deg2rad($derece);
        $c = cos($a); $s = sin($a);
        $px = $cx * self::K; $py = ($this->h - $cy) * self::K;
        $this->yaz('q ' . implode(' ', array_map([self::class, 'n'], [$c, $s, -$s, $c, $px - $c * $px + $s * $py, $py - $s * $px - $c * $py])) . ' cm');
        $ciz($this);
        $this->yaz('Q');
    }

    /** UTF-16BE PDF metin dizesi (meta veriler için) */
    private static function utf16(string $s): string
    {
        return '<FEFF' . strtoupper(bin2hex(mb_convert_encoding($s, 'UTF-16BE', 'UTF-8'))) . '>';
    }

    /** PDF içeriğini üretir */
    public function cikti(): string
    {
        $obj = [];
        $obj[1] = '<< /Type /Catalog /Pages 2 0 R >>';
        $enc = '<< /Type /Encoding /BaseEncoding /WinAnsiEncoding /Differences [208 /Gbreve 221 /Idotaccent /Scedilla 240 /gbreve 253 /dotlessi /scedilla] >>';
        $obj[3] = $enc;
        $obj[4] = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding 3 0 R >>';
        $obj[5] = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold /Encoding 3 0 R >>';
        $tz = date('O');
        $obj[6] = '<< /Title ' . self::utf16($this->baslik) . ' /Producer ' . self::utf16('Apartman Yönetim Sistemi')
            . ' /CreationDate (D:' . date('YmdHis') . substr($tz, 0, 3) . "'" . substr($tz, 3, 2) . "') >>";
        $kids = [];
        $no = 7;
        $sikistir = function_exists('gzcompress');
        foreach ($this->sayfalar as $icerik) {
            $sayfaNo = $no++; $icerikNo = $no++;
            $kids[] = "$sayfaNo 0 R";
            $obj[$sayfaNo] = '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 ' . self::n($this->w * self::K) . ' ' . self::n($this->h * self::K) . ']'
                . ' /Resources << /Font << /F1 4 0 R /F2 5 0 R >> >> /Contents ' . $icerikNo . ' 0 R >>';
            $veri = $sikistir ? gzcompress($icerik) : $icerik;
            $obj[$icerikNo] = '<< /Length ' . strlen($veri) . ($sikistir ? ' /Filter /FlateDecode' : '') . " >>\nstream\n" . $veri . "\nendstream";
        }
        $obj[2] = '<< /Type /Pages /Kids [' . implode(' ', $kids) . '] /Count ' . count($kids) . ' >>';
        ksort($obj);

        $pdf = "%PDF-1.4\n%\xE2\xE3\xCF\xD3\n";
        $ofs = [];
        foreach ($obj as $i => $o) {
            $ofs[$i] = strlen($pdf);
            $pdf .= "$i 0 obj\n$o\nendobj\n";
        }
        $xref = strlen($pdf);
        $adet = max(array_keys($obj)) + 1;
        $pdf .= "xref\n0 $adet\n0000000000 65535 f \n";
        for ($i = 1; $i < $adet; $i++) $pdf .= sprintf("%010d 00000 n \n", $ofs[$i] ?? 0);
        $pdf .= "trailer\n<< /Size $adet /Root 1 0 R /Info 6 0 R >>\nstartxref\n$xref\n%%EOF";
        return $pdf;
    }

    /** Tarayıcıya gönderir */
    public function gonder(string $dosyaAdi, bool $indir = false): void
    {
        $veri = $this->cikti();
        while (ob_get_level()) ob_end_clean();
        header('Content-Type: application/pdf');
        header('Content-Length: ' . strlen($veri));
        header('Content-Disposition: ' . ($indir ? 'attachment' : 'inline') . '; filename="' . $dosyaAdi . '"');
        header('Cache-Control: private, max-age=0, must-revalidate');
        echo $veri;
    }
}

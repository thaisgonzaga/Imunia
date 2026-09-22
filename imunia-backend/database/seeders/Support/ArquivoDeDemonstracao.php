<?php

namespace Database\Seeders\Support;

/**
 * Gera os arquivos dos anexos do cenário de demonstração (RF32). Existe para
 * que T08 possa ser vista com anexos que abrem de verdade — um registro de
 * anexo sem arquivo no disco cairia no estado "indisponível", que é justamente
 * o estado excepcional que a tela prevê, e não o que a demonstração quer
 * mostrar.
 *
 * Os arquivos são gerados, e não versionados no repositório, porque conteúdo
 * clínico de demonstração em imagem e PDF ocuparia espaço e pediria origem: um
 * laudo de exame plausível o bastante para a tela é plausível o bastante para
 * ser confundido com um laudo real.
 */
class ArquivoDeDemonstracao
{
    /**
     * Imagem com a legenda escrita, para que o visualizador de T08 exiba algo
     * identificável. Sem a extensão GD, devolve um PNG mínimo: o anexo continua
     * existindo e abrindo, apenas sem a legenda.
     */
    public static function png(string $legenda): string
    {
        if (! function_exists('imagecreatetruecolor')) {
            return (string) base64_decode(
                'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg=='
            );
        }

        $imagem = imagecreatetruecolor(640, 400);
        $fundo = imagecolorallocate($imagem, 237, 241, 238);
        $traco = imagecolorallocate($imagem, 195, 205, 199);
        $tinta = imagecolorallocate($imagem, 86, 103, 95);

        imagefill($imagem, 0, 0, $fundo);
        imagerectangle($imagem, 16, 16, 623, 383, $traco);
        imagestring($imagem, 5, 40, 180, self::semAcento($legenda), $tinta);
        imagestring($imagem, 3, 40, 210, 'Imunia - imagem de demonstracao', $traco);

        ob_start();
        imagepng($imagem);
        imagedestroy($imagem);

        return (string) ob_get_clean();
    }

    /**
     * PDF de uma página, montado à mão. A tabela de referência cruzada é
     * calculada a partir do que já foi escrito, e não estimada: um `xref` com
     * deslocamento errado produz arquivo que alguns leitores abrem e outros
     * recusam, o que seria uma falha difícil de diagnosticar pela tela.
     *
     * @param list<string> $linhas
     */
    public static function pdf(string $titulo, array $linhas): string
    {
        $conteudo = "BT\n/F1 16 Tf\n60 760 Td\n(".self::escapar($titulo).") Tj\n/F1 11 Tf\n";

        foreach ($linhas as $linha) {
            $conteudo .= "0 -24 Td\n(".self::escapar($linha).") Tj\n";
        }

        $conteudo .= 'ET';

        $objetos = [
            '<< /Type /Catalog /Pages 2 0 R >>',
            '<< /Type /Pages /Kids [3 0 R] /Count 1 >>',
            '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 595 842] '
                .'/Resources << /Font << /F1 5 0 R >> >> /Contents 4 0 R >>',
            '<< /Length '.strlen($conteudo)." >>\nstream\n{$conteudo}\nendstream",
            '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding /WinAnsiEncoding >>',
        ];

        $pdf = "%PDF-1.4\n";
        $deslocamentos = [];

        foreach ($objetos as $indice => $corpo) {
            $deslocamentos[] = strlen($pdf);
            $pdf .= ($indice + 1)." 0 obj\n{$corpo}\nendobj\n";
        }

        $inicioDaTabela = strlen($pdf);
        $total = count($objetos) + 1;

        $pdf .= "xref\n0 {$total}\n0000000000 65535 f \n";

        foreach ($deslocamentos as $deslocamento) {
            $pdf .= sprintf("%010d 00000 n \n", $deslocamento);
        }

        return $pdf."trailer\n<< /Size {$total} /Root 1 0 R >>\nstartxref\n{$inicioDaTabela}\n%%EOF";
    }

    /**
     * Helvetica com `WinAnsiEncoding` não fala UTF-8: o texto precisa chegar em
     * CP1252, ou os acentos viram dois caracteres na página.
     */
    private static function escapar(string $texto): string
    {
        $convertido = iconv('UTF-8', 'CP1252//TRANSLIT', $texto);

        return str_replace(['\\', '(', ')'], ['\\\\', '\\(', '\\)'], $convertido === false ? $texto : $convertido);
    }

    /**
     * A fonte embutida do GD é ASCII pura — sem isto, "cutâneo" sai truncado.
     */
    private static function semAcento(string $texto): string
    {
        $convertido = iconv('UTF-8', 'ASCII//TRANSLIT', $texto);

        return $convertido === false ? $texto : $convertido;
    }
}

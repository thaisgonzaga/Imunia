<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Reparo do que a gravação silenciosa deixou no banco.
 *
 * Enquanto o disco da nuvem devolvia a falha como `false` em vez de lançar
 * (config/filesystems.php), esse `false` era gravado em `foto_caminho` como se
 * fosse o caminho do arquivo, e o banco o guardou como "0". A ficha do animal
 * então pedia ao armazenamento um arquivo chamado "0", que nunca existiu, e o
 * tutor via a imagem quebrada no lugar do seu animal.
 *
 * Sem caminho válido não há fotografia: o registro volta a dizer a verdade, e o
 * tutor pode enviar a imagem de novo — agora com a falha aparecendo na hora,
 * caso ela se repita.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Toda fotografia gravada com sucesso está sob `Animal::PASTA_DA_FOTO`.
        // O que não estiver lá não é caminho de arquivo algum.
        DB::table('animais')
            ->whereNotNull('foto_caminho')
            ->where('foto_caminho', 'not like', 'animais/fotos/%')
            ->update(['foto_caminho' => null]);
    }

    public function down(): void
    {
        // Não há o que devolver: o valor apagado não apontava para arquivo
        // nenhum, nem aqui nem no bucket.
    }
};

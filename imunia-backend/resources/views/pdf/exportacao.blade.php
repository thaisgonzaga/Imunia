@php
    use App\Services\ExportacaoDeHistoricoService as Datas;

    $animal = $documento['animal'];
    $especie = $animal['especie'] === 'gato' ? 'Gato' : 'Cão';
    $titulo = $documento['tipo'] === 'carteira' ? 'Carteira de vacinação' : 'Histórico do animal';
@endphp
<!DOCTYPE html>
<html lang="pt-br">
<head>
    <meta charset="utf-8">
    <title>{{ $titulo }} · {{ $animal['nome'] }}</title>
    <style>
        /* O dompdf não alcança as fontes da tela; DejaVu Sans é a que vem com
           ele e cobre o português inteiro, acentos incluídos.

           Sem reset universal: um `* { margin: 0 }` faz o dompdf 3 descartar
           silenciosamente os elementos `position: fixed` — o rodapé some do
           PDF inteiro. Os resets vão nos elementos, um a um. */
        body, h1, h2, p, table, td, th, div { margin: 0; padding: 0; }
        @page { margin: 96px 48px 150px 48px; }

        body {
            font-family: 'DejaVu Sans', sans-serif;
            font-size: 10px;
            line-height: 1.5;
            color: #14231F;
        }

        /* Cabeçalho e rodapé fixos: repetem-se em toda página, porque cada
           página pode circular sozinha numa fotocópia. */
        .cabecalho-pagina {
            position: fixed;
            top: -64px;
            left: 0;
            right: 0;
            border-bottom: 2px solid #1E6B54;
            padding-bottom: 8px;
        }

        .marca { font-size: 16px; font-weight: bold; color: #1E6B54; }
        .titulo-documento { float: right; font-size: 12px; margin-top: 4px; }

        .rodape-pagina {
            position: fixed;
            bottom: -126px;
            left: 0;
            right: 0;
            border-top: 1px solid #C9D6D1;
            padding-top: 8px;
            font-size: 8px;
            color: #4A5B55;
        }

        /* RN46 — a advertência é do rodapé por exigência expressa de RF46c. */
        .advertencia { margin-bottom: 6px; font-style: italic; }

        .verificacao { width: 100%; }
        .verificacao td { vertical-align: top; }
        .verificacao .qr { width: 76px; }
        .verificacao img { width: 68px; height: 68px; }
        .verificacao .codigo { font-family: 'DejaVu Sans Mono', monospace; font-size: 10px; color: #14231F; }

        h1 { font-size: 18px; margin-bottom: 2px; }

        .identificacao { width: 100%; margin: 12px 0 16px; border-collapse: collapse; }
        .identificacao td { padding: 2px 16px 2px 0; }
        .identificacao .rotulo { color: #4A5B55; font-size: 8px; text-transform: uppercase; letter-spacing: .06em; }
        .identificacao .valor { font-size: 11px; }
        .codigo-animal { font-family: 'DejaVu Sans Mono', monospace; }

        .aviso-preliminar {
            margin-bottom: 12px;
            padding: 6px 8px;
            background: #F2EFE7;
            border: 1px solid #B7A468;
            font-size: 9px;
        }

        h2 {
            font-size: 12px;
            margin: 14px 0 6px;
            padding-bottom: 3px;
            border-bottom: 1px solid #C9D6D1;
        }

        .situacao { font-size: 9px; color: #4A5B55; font-weight: normal; }

        table.registros { width: 100%; border-collapse: collapse; margin-bottom: 8px; }
        table.registros th {
            text-align: left;
            font-size: 8px;
            text-transform: uppercase;
            letter-spacing: .06em;
            color: #4A5B55;
            padding: 3px 8px 3px 0;
            border-bottom: 1px solid #C9D6D1;
        }
        table.registros td {
            padding: 5px 8px 5px 0;
            border-bottom: 1px solid #E4EBE8;
            vertical-align: top;
        }

        /* RF46b — o registro não verificado é assinalado também no papel, com a
           mesma palavra que a tela usa (RN24). */
        .nao-verificado { color: #8A6D1C; font-size: 8px; }

        .procedencia { color: #4A5B55; font-size: 9px; }

        .entrada { margin-bottom: 10px; page-break-inside: avoid; }
        .entrada .data { font-size: 9px; color: #4A5B55; }
        .entrada .titulo { font-size: 11px; font-weight: bold; }
        .entrada .resumo { margin-top: 1px; }
    </style>
</head>
<body>
    <div class="cabecalho-pagina">
        <span class="marca">Imunia</span>
        <span class="titulo-documento">{{ $titulo }}</span>
    </div>

    <div class="rodape-pagina">
        <p class="advertencia">
            Ao compartilhar este arquivo, os dados saem do controle da plataforma e a
            responsabilidade pela difusão passa a ser sua.
        </p>
        <table class="verificacao">
            <tr>
                <td class="qr"><img src="{{ $qrCode }}" alt="QR Code de verificação"></td>
                <td>
                    Documento emitido pelo Imunia em {{ $exportacao->emitido_em->format('d/m/Y \à\s H:i') }}.<br>
                    Emissão <span class="codigo">{{ $exportacao->codigoFormatado() }}</span>
                    · resumo <span class="codigo">{{ $exportacao->resumoAbreviado() }}</span><br>
                    Confira a autenticidade pelo QR Code ou em {{ $linkVerificacao }}
                </td>
            </tr>
        </table>
    </div>

    <div>
        <h1>{{ $animal['nome'] }}</h1>

        <table class="identificacao">
            <tr>
                <td>
                    <div class="rotulo">Espécie</div>
                    <div class="valor">{{ $especie }}</div>
                </td>
                <td>
                    <div class="rotulo">Nascimento</div>
                    <div class="valor">
                        {{ Datas::dataPorExtenso($animal['nascimento']) }}{{ $animal['nascimento'] !== null && ! $animal['nascimento_exato'] ? ' (estimado)' : '' }}
                    </div>
                </td>
                <td>
                    <div class="rotulo">Código do animal</div>
                    <div class="valor codigo-animal">{{ $animal['codigo'] }}</div>
                </td>
                <td>
                    <div class="rotulo">Tutor</div>
                    <div class="valor">{{ $documento['tutor'] }}</div>
                </td>
            </tr>
        </table>

        {{-- RN17 — a pendência acompanha o animal onde quer que ele apareça,
             inclusive no papel. --}}
        @if ($animal['preliminar'])
            <p class="aviso-preliminar">
                Cadastro preliminar: um veterinário ainda não completou a caracterização deste animal.
            </p>
        @endif

        @if ($documento['tipo'] === 'carteira')
            @foreach ($documento['carteira']['grupos'] as $grupo)
                <h2>
                    {{ $grupo['imunobiologico']['nome'] }}
                    <span class="situacao">· {{ $grupo['situacao']['texto'] }}</span>
                </h2>
                <table class="registros">
                    <tr>
                        <th style="width: 18%">Dose</th>
                        <th style="width: 14%">Data</th>
                        <th style="width: 30%">Identificação</th>
                        <th>Origem do registro</th>
                    </tr>
                    @foreach ($grupo['aplicacoes'] as $aplicacao)
                        <tr>
                            <td>{{ ucfirst($aplicacao['rotulo']) }}</td>
                            <td>
                                {{ Datas::dataPorExtenso($aplicacao['data']) }}{{ $aplicacao['data'] !== null && $aplicacao['data_aproximada'] ? ' (aprox.)' : '' }}
                            </td>
                            <td>
                                @php
                                    $identificacao = array_filter([
                                        $aplicacao['fabricante'],
                                        $aplicacao['lote'] === null ? null : 'lote '.$aplicacao['lote'],
                                        $aplicacao['validade'] === null ? null : 'val. '.Datas::dataPorExtenso($aplicacao['validade']),
                                    ]);
                                @endphp
                                {{ $identificacao === [] ? 'não informada' : implode(' · ', $identificacao) }}
                            </td>
                            <td>
                                @if ($aplicacao['origem'] === 'pregresso')
                                    Informado pelo tutor
                                    <div class="nao-verificado">registro não verificado</div>
                                @else
                                    {{ $aplicacao['aplicador']['prestador'] ?? 'Prestador não identificado' }}
                                    <div class="procedencia">
                                        {{ $aplicacao['aplicador']['nome'] }}
                                        @if ($aplicacao['aplicador']['crmv'] !== null)
                                            · {{ $aplicacao['aplicador']['crmv'] }}
                                        @endif
                                    </div>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </table>
            @endforeach

            @if ($documento['carteira']['proximas_doses'] !== [])
                <h2>Próximas doses previstas</h2>
                <table class="registros">
                    <tr>
                        <th style="width: 32%">Imunobiológico</th>
                        <th style="width: 18%">Dose</th>
                        <th style="width: 16%">Prevista para</th>
                        <th>Situação</th>
                    </tr>
                    @foreach ($documento['carteira']['proximas_doses'] as $dose)
                        <tr>
                            <td>{{ $dose['imunobiologico'] }}</td>
                            <td>{{ ucfirst($dose['rotulo_curto']) }}</td>
                            <td>{{ Datas::dataPorExtenso($dose['prevista_para']) }}</td>
                            <td>{{ $dose['situacao']['texto'] }}</td>
                        </tr>
                    @endforeach
                </table>
            @endif
        @else
            @if ($documento['periodo'] !== null)
                <p class="procedencia" style="margin-bottom: 10px;">
                    Recorte: últimos {{ $documento['periodo'] }} meses.
                </p>
            @endif

            @foreach ($documento['entradas'] as $entrada)
                <div class="entrada">
                    <div class="data">
                        {{ Datas::dataPorExtenso($entrada['data']) }}{{ $entrada['data'] !== null && $entrada['data_aproximada'] ? ' (aproximada)' : '' }}
                    </div>
                    <div class="titulo">{{ $entrada['titulo'] }}</div>
                    @if ($entrada['resumo'] !== '')
                        <div class="resumo">{{ $entrada['resumo'] }}</div>
                    @endif
                    <div class="procedencia">
                        {{ $entrada['prestador']['rotulo'] }}@if ($entrada['aplicador'] !== null && $entrada['aplicador']['nome'] !== null) · {{ $entrada['aplicador']['nome'] }}@if ($entrada['aplicador']['crmv'] ?? null) · {{ $entrada['aplicador']['crmv'] }}@endif @endif
                        @if ($entrada['origem'] === 'pregresso')
                            <span class="nao-verificado">· registro não verificado</span>
                        @endif
                    </div>
                </div>
            @endforeach
        @endif
    </div>
</body>
</html>

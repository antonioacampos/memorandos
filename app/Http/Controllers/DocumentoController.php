<?php

namespace App\Http\Controllers;

use App\Models\Documento;
use App\Models\Categoria;
use App\Models\Grupo;
use App\Models\Template;
use App\Models\Arquivo;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use \Spatie\Activitylog\Models\Activity;
use Barryvdh\DomPDF\Facade\Pdf;
use App\Services\Pdfgen;

class DocumentoController extends Controller
{
    /**
     * Gera código automático para documento
     * Formato: CATEGORIA Nº XXX/AAAA/GRUPO-e
     */
    private function gerarCodigo(string $prefixo, int $sequencial, int $ano): string
    {
        $numero = str_pad($sequencial, 3, '0', STR_PAD_LEFT);
        return "{$prefixo} Nº {$numero}/{$ano}";
    }

    /**
     * Exibe a lista de documentos do grupo ativo
     */
    public function index($categoria, $ano = null)
    {
        $this->authorize('grupoManager');
        \UspTheme::activeUrl('documentos');
        
        $grupoId = session('grupo_id');
        
        if (!$grupoId) {
            return redirect()->route('grupo.index')->with('alert-warning', 'Selecione um grupo primeiro.');
        }

        if (!Gate::allows('manager') && !Auth::user()->hasPermissionTo('manager_' . $grupoId)) {
            abort(403, 'Você não tem permissão para acessar este grupo.');
        }
        if (!$ano){
            $ano = date('Y'); // verificar ano nullable
        }

        $query = Documento::where('grupo_id', $grupoId)
            ->where('categoria_id', $categoria)
            ->where('ano', $ano)
            ->with(['categoria', 'template']);

        
        $anos = \App\Models\Documento::whereNotNull('ano')
            ->distinct()
            ->orderBy('ano', 'desc')
            ->pluck('ano');

        $documentos = $query->get();

        return view('documento.index', compact('documentos', 'categoria', 'anos', 'ano'));
    }


    /**
     * Exibe o formulário de criação de novo documento
     */
    public function create($categoria)
    {
        $this->authorize('grupoManager');

        $grupoId = session('grupo_id');
        
        if (!$grupoId) {
            return redirect()->route('grupo.index')->with('alert-warning', 'Selecione um grupo primeiro.');
        }

        if (!Gate::allows('manager') && !Auth::user()->hasPermissionTo('manager_' . $grupoId)) {
            abort(403, 'Você não tem permissão para criar documentos neste grupo.');
        }

        $templates = Categoria::findOrFail($categoria)->templates;
        
        return view('documento.create', compact('templates', 'categoria'));
    }

    /**
     * Armazena um novo documento no banco de dados
     */
    public function store(Request $request, $categoria)
    {
        $this->authorize('grupoManager');
        $grupoId = session('grupo_id');
        if (!$grupoId) {
            return redirect()->route('grupo.index')->with('alert-warning', 'Selecione um grupo primeiro.');
        }
        if (!Gate::allows('manager') && !Auth::user()->hasPermissionTo('manager_' . $grupoId)) {
            abort(403, 'Você não tem permissão para criar documentos neste grupo.');
        }

        $request->validate([
            'prefixo' => 'string|max:255|nullable',
            'sequencial' => 'integer|nullable',
            'ano' => 'integer|nullable',
            'codigo' => 'string|max:255|nullable',
            'destinatario' => 'required|string|max:255',
            'remetente' => 'required|string|max:255',
            'data_documento' => 'required|date',
            'assunto' => 'required|string',
            'mensagem' => 'required|string',
            'template_id' => 'nullable|exists:templates,id',
            'arquivo_id' => 'nullable|exists:documentos,id',
        ]);

        $categoria = Categoria::findOrFail($categoria);
        if ($categoria->grupo_id != $grupoId) {
            abort(403, 'Categoria não pertence ao grupo selecionado.');
        }

        $codigo = null;

        $docData = [
            'destinatario' => $request->destinatario,
            'remetente' => $request->remetente,
            'data_documento' => $request->data_documento,
            'assunto' => $request->assunto,
            'mensagem' => $request->mensagem,
            'categoria_id' => $categoria->id,
            'template_id' => $request->template_id,
            'arquivo_id' => $request->arquivo_id,
            'grupo_id' => $grupoId,
            'user_id' => Auth::id(),
        ];

        if($categoria->controlar_sequencial){
            $ano = date('Y');
            $ultimoDocumento = Documento::where('categoria_id', $categoria->id)
                ->where('grupo_id', $grupoId)
                ->where('ano', $ano)
                ->orderByDesc('id')
                ->first();

            $ultimoSequencial = $ultimoDocumento ? $ultimoDocumento->sequencial : null;

            $sequencial = $ultimoSequencial ? $ultimoSequencial + 1 : 1;
            $codigo = $this->gerarCodigo($request->prefixo, $sequencial, $ano);

            $docData['codigo'] = $codigo;
            $docData['sequencial'] = $sequencial;
            $docData['ano'] = $ano;
        } else {
            if($request->ano){
                $docData['ano'] = $request->ano;
            }
            if($request->sequencial){
                $docData['sequencial'] = $request->sequencial;
            }
            if($request->codigo){
                $codigoExists = Documento::where('categoria_id', $categoria->id)
                    ->where('grupo_id', $grupoId)->where('codigo', $request->codigo)->exists();
                if(!$codigoExists){
                    $docData['codigo'] = $request->codigo;
                    $codigo = $request->codigo;
                }
            } elseif($request->ano && $request->sequencial){
                $codigo = $this->gerarCodigo($request->prefixo, $request->sequencial, $request->ano);
                $codigoExists = Documento::where('categoria_id', $categoria->id)
                    ->where('grupo_id', $grupoId)->where('codigo', $codigo)->exists();
                if(!$codigoExists){
                    $docData['codigo'] = $codigo;
                } else {
                    $codigo = null;
                }
            }
        }

        $documento = Documento::create($docData);

        if ($request->hasFile('arquivos')) {
            foreach ($request->file('arquivos') as $file) {
                $path = $file->store('documentos/arquivos', 'public');
                $documento->arquivos()->create([
                    'nome_original' => $file->getClientOriginalName(),
                    'tamanho' => $file->getSize(),
                    'tipo_mime' => $file->getClientMimeType(),
                    'tipo_arquivo' => 'upload',
                    'caminho' => $path,
                    'user_id' => Auth::id(),
                ]);
            }
        }
        

        session()->flash('alert-success', 'Documento criado com sucesso! Código ' . ($codigo ?? $documento->codigo ?? 'não definido'));
        return redirect()->route('documento.show', $documento);
    }
 
    /**
     * Exibe um documento específico
     *
     * @param int $id
     * @return \Illuminate\View\View
     */
    public function show($id)
    {
        $documento = Documento::with(['categoria.grupo', 'template', 'arquivos'])->findOrFail($id);

        if (!Gate::allows('manager') && !Auth::user()->hasPermissionTo('manager_' . $documento->categoria->grupo_id)) {
            abort(403, 'Você não tem permissão para visualizar este documento.');
        }

        $activities =  Activity::orderBy('created_at', 'DESC')->where('subject_id', $id)->get();

        return view('documento.show', compact('documento', 'activities'));
    }

    /**
     * Exibe o formulário de edição de documento
     * 
     * @param int $id
     * @return \Illuminate\View\View
     */
    public function edit($categoria, $id)
    {
        $documento = Documento::with(['categoria.grupo', 'template', 'arquivos'])->findOrFail($id);

        if (!Gate::allows('manager') && !Auth::user()->hasPermissionTo('manager_' . $documento->categoria->grupo_id)) {
            abort(403, 'Você não tem permissão para editar este documento.');
        }

        if ($documento->finalizado) {
            session()->flash('alert-warning', 'Este documento já foi finalizado e não pode ser editado.');
            return redirect()->route('documento.show', $id);
        }

        $templates = Categoria::findOrFail($categoria)->templates;

        return view('documento.create', compact('documento', 'categoria', 'templates'));
    }

    /**
     * Atualiza os dados de um documento existente
     * 
     * @param Request $request
     * @param int $id
     * @return \Illuminate\Http\RedirectResponse
     */
    public function update(Request $request, $id)
    {
        $documento = Documento::findOrFail($id);

        if (!Gate::allows('manager') && !Auth::user()->hasPermissionTo('manager_' . $documento->categoria->grupo_id)) {
            abort(403, 'Você não tem permissão para editar este documento.');
        }

        if ($documento->finalizado) {
            session()->flash('alert-danger', 'Este documento já foi finalizado e não pode ser editado.');
            return redirect()->route('documento.show', $id);
        }

        $request->validate([
            'prefixo' => 'string|max:255|nullable',
            'sequencial' => 'integer|nullable',
            'ano' => 'integer|nullable',
            'codigo' => 'string|max:255|nullable',
            'destinatario' => 'required|string|max:255',
            'remetente' => 'required|string|max:255',
            'data_documento' => 'required|date',
            'assunto' => 'required|string',
            'mensagem' => 'required|string',
            'template_id' => 'nullable|exists:templates,id',
        ]);

        if ($request->hasFile('arquivos')) {
            foreach ($request->file('arquivos') as $file) {
                $path = $file->store('documentos/arquivos', 'public');
                $documento->arquivos()->create([
                    'nome_original' => $file->getClientOriginalName(),
                    'tamanho' => $file->getSize(),
                    'tipo_mime' => $file->getClientMimeType(),
                    'tipo_arquivo' => 'upload',
                    'caminho' => $path,
                    'user_id' => Auth::id(),
                ]);
            }
        }

        $categoria = Categoria::findOrFail($documento->categoria_id);

        if (!Gate::allows('manager') && !Auth::user()->hasPermissionTo('manager_' . $categoria->grupo_id)) {
            abort(403, 'Você não tem permissão para mover este documento para esta categoria.');
        }

        $updateData = [
            'destinatario' => $request->destinatario,
            'remetente' => $request->remetente,
            'data_documento' => $request->data_documento,
            'assunto' => $request->assunto,
            'mensagem' => $request->mensagem,
            'template_id' => $request->template_id,
            'grupo_id' => $categoria->grupo_id,
        ];

        $codigo = null;

        if ($categoria->controlar_sequencial) {
            $ano = $documento->ano ?? date('Y');
            $sequencial = $documento->sequencial;

            if (!$sequencial) {
                $ultimoDocumento = Documento::where('categoria_id', $categoria->id)
                    ->where('grupo_id', $categoria->grupo_id)
                    ->where('ano', $ano)
                    ->orderByDesc('id')
                    ->first();

                $ultimoSequencial = $ultimoDocumento ? $ultimoDocumento->sequencial : null;

                $sequencial = $ultimoSequencial ? $ultimoSequencial + 1 : 1;
            }

            $codigo = $this->gerarCodigo($request->prefixo, $sequencial, $ano);

            $codigoExists = Documento::where('categoria_id', $categoria->id)
                ->where('grupo_id', $categoria->grupo_id)
                ->where('codigo', $codigo)
                ->where('id', '!=', $documento->id)
                ->exists();

            if ($codigoExists) {
                session()->flash('alert-danger', 'Já existe um documento com este código.');
                return redirect()->back()->withInput();
            }

            $updateData['sequencial'] = $sequencial;
            $updateData['ano'] = $ano;
            $updateData['codigo'] = $codigo;
        } else {
            if ($request->ano) {
                $updateData['ano'] = $request->ano;
            }
            if ($request->sequencial) {
                $updateData['sequencial'] = $request->sequencial;
            }
            if ($request->codigo) {
                $codigoExists = Documento::where('categoria_id', $categoria->id)
                    ->where('grupo_id', $categoria->grupo_id)
                    ->where('codigo', $request->codigo)
                    ->where('id', '!=', $documento->id)
                    ->exists();
                if ($codigoExists) {
                    session()->flash('alert-danger', 'Já existe um documento com este código.');
                    return redirect()->back()->withInput();
                }
                $updateData['codigo'] = $request->codigo;
                $codigo = $request->codigo;
            } elseif ($request->ano && $request->sequencial) {
                $codigo = $this->gerarCodigo($request->prefixo, $request->sequencial, $request->ano);
                $codigoExists = Documento::where('categoria_id', $categoria->id)
                    ->where('grupo_id', $categoria->grupo_id)
                    ->where('codigo', $codigo)
                    ->where('id', '!=', $documento->id)
                    ->exists();
                if ($codigoExists) {
                    session()->flash('alert-danger', 'Já existe um documento com este código.');
                    return redirect()->back()->withInput();
                }
                $updateData['codigo'] = $codigo;
            }
        }

        $documento->update($updateData);

        session()->flash('alert-success', 'Documento atualizado com sucesso! Código ' . ($codigo ?? $documento->codigo ?? 'não definido'));
        return redirect()->route('documento.edit', ['categoria' => $documento->categoria_id, 'id' => $id]);
    }

    /**
     * Finaliza um documento
     * 
     * @param int $id
     * @return \Illuminate\Http\RedirectResponse
     */
    public function finalizar($id)
    {
        $documento = Documento::findOrFail($id);

        if (!Gate::allows('manager') && !Auth::user()->hasPermissionTo('manager_' . $documento->categoria->grupo_id)) {
            abort(403, 'Você não tem permissão para finalizar este documento.');
        }

        if ($documento->finalizado) {
            session()->flash('alert-warning', 'Este documento já foi finalizado.');
            return redirect()->route('documento.show', $id);
        }

        $documento->update([
            'finalizado' => true,
            'data_finalizacao' => now(),
            'finalizer_user_id' => Auth::id(),
        ]);

        session()->flash('alert-success', 'Documento finalizado com sucesso!');
        return redirect()->route('documento.show', $id);
    }

    /**
     * Remove permanentemente um documento
     * 
     * @param int $id
     * @return \Illuminate\Http\RedirectResponse
     */
    public function destroy($id)
    {
        $documento = Documento::findOrFail($id);

        if (!Gate::allows('manager') && !Auth::user()->hasPermissionTo('manager_' . $documento->categoria->grupo_id)) {
            abort(403, 'Você não tem permissão para excluir este documento.');
        }

        if ($documento->finalizado) {
            session()->flash('alert-danger', 'Não é possível excluir um documento finalizado.');
            return redirect()->route('documento.show', $id);
        }

        foreach ($documento->arquivos as $arquivo) {
            Storage::delete($arquivo->caminho);
        }
        $categoria = $documento->categoria_id;
        $documento->delete();

        session()->flash('alert-success', 'Documento removido com sucesso!');
        return redirect()->route('documento.index', $categoria);
    }

    public function detalharAtividade($id)
    {
        $activity = Activity::findOrFail($id);

        $documento = Documento::findOrFail($activity->subject_id);
        if (!Gate::allows('manager') && !Auth::user()->hasPermissionTo('manager_' . $documento->categoria->grupo_id)) {
            abort(403, 'Você não tem permissão para visualizar este documento.');
        }

        $old = $activity->properties['old'] ?? [];
        $new = $activity->properties['attributes'] ?? [];
        return view('documento.atividade', compact('activity', 'old', 'new'));
    }

    public function gerarPdf($id)
    {
        $documento = Documento::with('template', 'categoria', 'categoria.grupo')->findOrFail($id);

        $template = $documento->template;

        if (!$template) {
            return redirect()->back()->with('alert-danger', 'Documento não possui template associado.');
        }

        $variaveis = [
            'codigo'        => $documento->codigo,
            'remetente'     => $documento->remetente,
            'destinatario'  => $documento->destinatario,
            'data'          => $documento->data_documento->format('d/m/Y'),
            'assunto'       => $documento->assunto,
            'mensagem'      => $documento->mensagem,
        ];

        $docName = $documento->categoria->grupo->name . '_' . $documento->categoria->prefixo . '_';
        $codigo = '';
        if (preg_match('/Nº (\d+)\//', $documento->codigo, $matches)) {
            $codigo = $matches[1];
        }
        $docName .= $codigo . '.pdf';

        $pdfContent = null;
        $arquivoHash = '';
        $caminhoArquivo = '';

        if ($template->arquivo) {
            $fieldMap = [];
            if ($template->variaveis) {
                $fieldMap = is_array($template->variaveis)
                    ? $template->variaveis
                    : json_decode($template->variaveis, true);
            }

            $pdfgen = new Pdfgen();
            $pdfgen->setTemplate(public_path('storage/' . $template->arquivo));
            $pdfgen->setData($variaveis);
            $pdfgen->parse();

            $arquivoHash = $pdfgen->getHash($fieldMap);
            $caminhoArquivo = 'documentos/gerados/' . $arquivoHash . '.pdf';
            $fullPath = Storage::disk('public')->path($caminhoArquivo);

            $arquivoExistente = Arquivo::where('documento_id', $documento->id)
                ->where('tipo_arquivo', 'gerado')
                ->where('caminho', $caminhoArquivo)
                ->first();

            if ($arquivoExistente && Storage::disk('public')->exists($caminhoArquivo)) {
                $pdfContent = Storage::disk('public')->get($caminhoArquivo);
            } else {
                $pdfgen->pdfBuild('F', ['paper'=>'a4', 'orientation' => 'portrait'], $fieldMap, $fullPath);
                
                $pdfContent = Storage::disk('public')->get($caminhoArquivo);
                Arquivo::updateOrCreate(
                    [
                        'documento_id' => $documento->id,
                        'tipo_arquivo' => 'gerado',
                        'nome_original' => $docName,
                        'caminho' => $caminhoArquivo,
                        'tipo_mime' => 'application/pdf',
                        'tamanho' => strlen($pdfContent),
                        'user_id' => auth()->id(),
                    ]
                );
            }
        } else {
            $conteudo = $template->conteudo_padrao;
            
            foreach ($variaveis as $chave => $valor) {
                $conteudo = str_replace('{{ '.$chave.' }}', $valor, $conteudo);
            }
            
            $arquivoHash = md5($conteudo);
            $caminhoArquivo = 'documentos/gerados/' . $arquivoHash . '.pdf';
            $fullPath = Storage::disk('public')->path($caminhoArquivo);

            $arquivoExistente = Arquivo::where('documento_id', $documento->id)
                ->where('tipo_arquivo', 'gerado')
                ->where('caminho', $caminhoArquivo)
                ->first();

            if ($arquivoExistente && Storage::disk('public')->exists($caminhoArquivo)) {
                $pdfContent = Storage::disk('public')->get($caminhoArquivo);
            } else {
                $pdf = Pdf::loadHTML($conteudo);
                $pdf->save($fullPath); 
                
                $pdfContent = Storage::disk('public')->get($caminhoArquivo);

                Arquivo::updateOrCreate(
                    [
                        'documento_id' => $documento->id,
                        'tipo_arquivo' => 'gerado',
                        'nome_original' => $docName,
                        'caminho' => $caminhoArquivo,
                        'tipo_mime' => 'application/pdf',
                        'tamanho' => strlen($pdfContent),
                        'user_id' => auth()->id(),
                    ]
                );
            }
        }

        return response($pdfContent)
            ->header('Content-Type', 'application/pdf')
            ->header('Content-Disposition', 'attachment; filename="'.$docName.'"');
    }

    public function copy($id)
    {
        $documento = Documento::with('arquivos')->findOrFail($id);
        $categoria = $documento->categoria;
        $grupoId = $documento->grupo_id;

        $novoDocumento = $documento->replicate(['sequencial', 'ano', 'codigo', 'created_at', 'updated_at']);
        $novoDocumento->finalizado = false;

        if ($categoria->controlar_sequencial) {
            $ano = date('Y');
            $ultimoDocumento = Documento::where('categoria_id', $categoria->id)
                ->where('grupo_id', $grupoId)
                ->where('ano', $ano)
                ->orderByDesc('id')
                ->first();

            $ultimoSequencial = $ultimoDocumento ? $ultimoDocumento->sequencial : null;

            $sequencial = $ultimoSequencial ? $ultimoSequencial + 1 : 1;
            $novoDocumento->ano = $ano;
            $novoDocumento->sequencial = $sequencial;
            $prefixo = \Illuminate\Support\Str::beforeLast($documento->codigo, ' Nº');
            $novoDocumento->codigo = $this->gerarCodigo($prefixo, $sequencial, $ano);
        } else {
            $novoDocumento->ano = null;
            $novoDocumento->sequencial = null;
            $novoDocumento->codigo = null;
        }

        $novoDocumento->save();

        foreach ($documento->arquivos as $arquivo) {
            $novoArquivo = $arquivo->replicate(['id', 'created_at', 'updated_at']);
            $novoArquivo->documento_id = $novoDocumento->id;
            $novoArquivo->save();
        }

        session()->flash('alert-success', 'Documento clonado com sucesso! Código ' . ($novoDocumento->codigo ?? 'não definido'));
        return redirect()->route('documento.edit', ['categoria' => $novoDocumento->categoria_id, 'id' => $novoDocumento->id]);
    }

}

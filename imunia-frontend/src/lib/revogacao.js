/**
 * O texto que o tutor lê antes de revogar uma autorização (RF39d).
 *
 * Vive num lugar só porque duas telas o exibem — T12, onde o tutor administra
 * o que concedeu, e T14, onde ele reage ao que descobriu que foi acessado — e
 * porque a explicação é requisito, não cortesia. O bloco do que **não**
 * acontece é o que impede o tutor de supor que revogar apaga o prontuário da
 * clínica: a guarda do registro é obrigação dela perante o Conselho Federal de
 * Medicina Veterinária, e não faculdade que o titular possa extinguir (RN40).
 *
 * Duplicar esse texto tela a tela seria criar dois lugares onde ele pode
 * envelhecer separadamente — e a versão desatualizada continuaria parecendo
 * correta para quem a lesse.
 */
export function textoDaRevogacao(clinica, animal) {
  return {
    titulo: `Revogar o acesso de ${clinica} ao histórico de ${animal}`,
    acontece: [
      `${clinica} deixa imediatamente de ver o histórico de ${animal} produzido por outros prestadores.`,
      'A data da revogação fica registrada e a clínica é comunicada.',
    ],
    naoAcontece: [
      `Os registros que ${clinica} mesma criou continuam sob a guarda dela, porque o Conselho Federal de Medicina Veterinária exige que o prontuário seja preservado.`,
      `Você não perde nada: esses registros continuam na carteira e no histórico de ${animal}, para você.`,
      'Revogar não apaga prontuário — nem aqui, nem na clínica.',
    ],
  }
}

/** O diálogo fechado, com as props obrigatórias em valores neutros. */
export const REVOGACAO_EM_REPOUSO = { titulo: '', acontece: [], naoAcontece: [] }

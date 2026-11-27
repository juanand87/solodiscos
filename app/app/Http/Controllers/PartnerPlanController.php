<?php
namespace App\Http\Controllers;
use App\Models\Store;
use App\Services\StorePartnerAccess;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\{Auth,DB};

class PartnerPlanController extends Controller {
    public function show(StorePartnerAccess $access){$store=Auth::guard('store')->user()->store;$package=base_path('integrations/solodiscos-woocommerce.zip');return view('portal.plan',compact('store')+['plan'=>config('partner-plan'),'partnerAccess'=>$access->status($store),'pluginHash'=>is_file($package)?hash_file('sha256',$package):null]);}
    public function request(Request $request){
        $account=Auth::guard('store')->user();
        $account->store->update(['partner_plan_requested_at'=>now(),'partner_plan_requested_by'=>$account->id,'partner_plan_requested_version'=>config('partner-plan.version')]);
        return redirect()->route('portal.plan')->with('status','Solicitud de formalización registrada. No activa el acuerdo, no equivale a una firma y no inicia cobros.');
    }
    public function terminate(Request $request){
        $request->validate(['confirm_termination'=>'accepted']);$account=Auth::guard('store')->user();
        DB::transaction(function()use($account){
            $store=Store::whereKey($account->store_id)->lockForUpdate()->firstOrFail();
            $store->update(['commercial_agreement_active'=>false,'commercial_agreement_terminated_at'=>now(),'commercial_agreement_terminated_by'=>$account->id]);
            DB::table('store_integrations')->where('store_id',$store->id)->update(['token_hash'=>null,'connected_token_hash'=>null,'updated_at'=>now()]);
        });
        return redirect()->route('portal.plan')->with('status','Término registrado. El acceso completo quedó desactivado y la clave fue revocada. Puedes desactivar el plugin en WordPress.');
    }
}

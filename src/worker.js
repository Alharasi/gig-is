const PRIVATE_REPO = "Alharasi/gig-is-data";
const DATA_PATH = "data/services.json";
const ALLOWED_CATEGORIES = new Set(["all","web","design","automation","data","content"]);
function cors(origin, env) {
  const allowed = new Set(["https://alharasi.github.io", ...(env.ALLOWED_ORIGINS || "").split(",").map(s=>s.trim()).filter(Boolean)]);
  const headers = {"Vary":"Origin","X-Content-Type-Options":"nosniff","Cache-Control":"no-store"};
  if (allowed.has(origin)) {headers["Access-Control-Allow-Origin"]=origin;headers["Access-Control-Allow-Methods"]="GET, OPTIONS";headers["Access-Control-Allow-Headers"]="Content-Type";}
  return headers;
}
function reply(data, status, headers) {return new Response(JSON.stringify(data),{status,headers:{"Content-Type":"application/json; charset=utf-8",...headers}});}
export default {
  async fetch(request, env) {
    const origin = request.headers.get("Origin") || "";
    const headers = cors(origin, env);
    if(request.method==="OPTIONS") return new Response(null,{status:204,headers});
    if(request.method!=="GET") return reply({error:"Method not allowed"},405,headers);
    const url = new URL(request.url);
    if(url.pathname==="/health") return reply({status:"ok",service:"gig-is-search"},200,headers);
    if(url.pathname!=="/search") return reply({error:"Not found"},404,headers);
    if(origin && !headers["Access-Control-Allow-Origin"]) return reply({error:"Origin not allowed"},403,headers);
    const category=url.searchParams.get("category") || "all";
    const level=Number(url.searchParams.get("level") ?? "0");
    const page=Number(url.searchParams.get("page") ?? "1");
    if(!ALLOWED_CATEGORIES.has(category)||!Number.isInteger(level)||level<0||level>2||!Number.isInteger(page)||page<1||page>10) return reply({error:"Invalid search parameters"},400,headers);
    if(!env.GITHUB_TOKEN) return reply({error:"Search service is not configured"},503,headers);
    try {
      const api="https://api.github.com/repos/"+PRIVATE_REPO+"/contents/"+DATA_PATH;
      const response=await fetch(api,{headers:{"Authorization":"Bearer "+env.GITHUB_TOKEN,"Accept":"application/vnd.github.raw+json","X-GitHub-Api-Version":"2022-11-28","User-Agent":"gig-is-worker"}});
      if(!response.ok) return reply({error:"Data source unavailable"},502,headers);
      const services=await response.json();
      if(!Array.isArray(services)) throw Error("Invalid data");
      const matches=services.filter(s=>s && s.status==="published" && Number.isInteger(s.level) && s.level<=level && (category==="all"||s.category===category));
      const start=(page-1)*3;
      const items=matches.slice(start,start+3).map(s=>({id:s.id,name:s.name,category:s.category,level:s.level,description:s.description,deliverable:s.deliverable,tools:Array.isArray(s.tools)?s.tools.map(t=>({name:t.name,url:t.url})):[]}));
      return reply({items,page,hasMore:start+3<matches.length},200,headers);
    } catch (_) {return reply({error:"Search temporarily unavailable"},502,headers);}
  }
};

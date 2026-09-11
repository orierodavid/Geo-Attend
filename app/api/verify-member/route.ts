import {NextResponse} from 'next/server'
const URL=process.env.NEXT_PUBLIC_SUPABASE_URL||'https://lkxvcphlrvaheccjraxm.supabase.co'
const KEY=process.env.NEXT_PUBLIC_SUPABASE_PUBLISHABLE_KEY||process.env.NEXT_PUBLIC_SUPABASE_ANON_KEY||''

export async function POST(req:Request){
 try{
  const {name,pin}=await req.json()
  const cleanName=typeof name==='string'?name.trim().replace(/\s+/g,' '):''
  if(!cleanName||typeof pin!=='string'||!/^\d{4}$/.test(pin)) return NextResponse.json({error:'Enter your first or last name and 4-digit PIN.'},{status:400})
  const r=await fetch(`${URL}/rest/v1/rpc/check_choir_member`,{method:'POST',headers:{apikey:KEY,Authorization:`Bearer ${KEY}`,'Content-Type':'application/json'},body:JSON.stringify({p_name:cleanName,p_pin:pin}),cache:'no-store'})
  if(!r.ok)return NextResponse.json({error:'Attendance service is temporarily unavailable.'},{status:503})
  const rows=await r.json(),member=Array.isArray(rows)?rows[0]:rows
  if(!member)return NextResponse.json({error:'Name or PIN is incorrect. Please try again.'},{status:401})
  return NextResponse.json({ok:true,member:{name:member.full_name}})
 }catch{return NextResponse.json({error:'Unable to verify your name and PIN.'},{status:500})}
}

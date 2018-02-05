<?php

namespace AppBundle\Controller;

use AppBundle\Entity\User;
use Symfony\Bundle\FrameworkBundle\Controller\Controller;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Request;
use Nelmio\ApiDocBundle\Annotation\Operation;
use Nelmio\ApiDocBundle\Annotation\Model;
use Swagger\Annotations as SWG;
use AppBundle\Entity\Deck;
use Symfony\Component\HttpFoundation\JsonResponse;

class Oauth2Controller extends Controller
{
    public function userAction(Request $request)
    {
        $response = new Response();
        $response->headers->add(array('Access-Control-Allow-Origin' => '*'));

        /** @var User $user */
        $user = $this->getUser();
        $data = [
            'id' => $user->getId(),
            'username' => $user->getUsername(),
            'email' => $user->getEmail(),
            'reputation' => $user->getReputation()
        ];
        $content = json_encode([
            'data' => [$data]
        ]);

        $response->headers->set('Content-Type', 'application/json');
        $response->setContent($content);
        return $response;
    }

    /**
     * Get the description of all the Decks of the authenticated user
     *
     * @Operation(
     *     tags={"Deck"},
     *     summary="All the Decks",
     *     @SWG\Response(
     *         response="200",
     *         description="Returned when successful"
     *     )
     * )
     *
     * @param Request $request
     */
    public function listDecksAction(Request $request)
    {
        $response = new Response();
        $response->headers->add(array('Access-Control-Allow-Origin' => '*'));
        
        /* @var $decks \AppBundle\Entity\Deck[] */
        $decks = $this->getDoctrine()->getRepository('AppBundle:Deck')->findBy(['user' => $this->getUser()]);

        $dateUpdates = array_map(function ($deck) {
            return $deck->getDateUpdate();
        }, $decks);
        
        $response->setLastModified(max($dateUpdates));
        if ($response->isNotModified($request)) {
            return $response;
        }

        $content = json_encode($decks);
        
        $response->headers->set('Content-Type', 'application/json');
        $response->setContent($content);
        return $response;
    }
    

    /**
     * Get the description of one Deck of the authenticated user
     *
     * @Operation(
     *     tags={"Deck"},
     *     summary="Load One Deck",
     *     @SWG\Response(
     *         response="200",
     *         description="Returned when successful"
     *     )
     * )
     *
     * @param Request $request
     */
    public function loadDeckAction($id)
    {
        $response = new Response();
        $response->headers->add(array('Access-Control-Allow-Origin' => '*'));
        
        /* @var $deck \AppBundle\Entity\Deck */
        $deck = $this->getDoctrine()->getRepository('AppBundle:Deck')->find($id);

        if ($deck->getUser()->getId() !== $this->getUser()->getId()) {
            throw $this->createAccessDeniedException("Access denied to this object.");
        }
        
        $response->setLastModified($deck->getDateUpdate());
        if ($response->isNotModified($request)) {
            return $response;
        }

        $content = json_encode($deck);
        
        $response->headers->set('Content-Type', 'application/json');
        $response->setContent($content);
        return $response;
    }
    

    /**
     * Save one Deck of the authenticated user. The parameters are the same as in the response to the load method, but only a few are writable.
     * So you can parse the result from the load, change a few values, then send the object as the param of an ajax request.
     * If successful, id of Deck is in the msg
     *
     * @Operation(
     *     tags={"Deck"},
     *     summary="Save One Deck",
     *     @SWG\Parameter(
     *         name="name",
     *         in="body",
     *         description="Name of the Deck",
     *         required=false,
     *         type="string",
     *         schema=""
     *     ),
     *     @SWG\Parameter(
     *         name="decklist_id",
     *         in="body",
     *         description="Identifier of the Decklist from which the Deck is copied",
     *         required=false,
     *         type="integer",
     *         schema=""
     *     ),
     *     @SWG\Parameter(
     *         name="description_md",
     *         in="body",
     *         description="Description of the Decklist in Markdown",
     *         required=false,
     *         type="string",
     *         schema=""
     *     ),
     *     @SWG\Parameter(
     *         name="faction_code",
     *         in="body",
     *         description="Code of the faction of the Deck",
     *         required=false,
     *         type="string",
     *         schema=""
     *     ),
     *     @SWG\Parameter(
     *         name="tags",
     *         in="body",
     *         description="Space-separated list of tags",
     *         required=false,
     *         type="string",
     *         schema=""
     *     ),
     *     @SWG\Parameter(
     *         name="slots",
     *         in="body",
     *         description="Content of the Decklist as a JSON object",
     *         required=false,
     *         type="string",
     *         schema=""
     *     ),
     *     @SWG\Response(
     *         response="200",
     *         description="Returned when successful"
     *     )
     * )
     *
     * @param Request $request
     */
    public function saveDeckAction($id, Request $request)
    {
        /* @var $deck \AppBundle\Entity\Deck */

        if (!$id) {
            $deck = new Deck();
            $this->getDoctrine()->getManager()->persist($deck);
        } else {
            $deck = $this->getDoctrine()->getRepository('AppBundle:Deck')->find($id);
            if ($deck->getUser()->getId() !== $this->getUser()->getId()) {
                throw $this->createAccessDeniedException("Access denied to this object.");
            }
        }
        
        $faction_code = filter_var($request->get('faction_code'), FILTER_SANITIZE_STRING, FILTER_FLAG_NO_ENCODE_QUOTES);
        if (!$faction_code) {
            return new JsonResponse([
                    'success' => false,
                    'msg' => "Faction code missing"
            ]);
        }
        $faction = $this->getDoctrine()->getManager()->getRepository('AppBundle:Faction')->findOneBy(['code' => $faction_code]);
        if (!$faction) {
            return new JsonResponse([
                    'success' => false,
                    'msg' => "Faction code invalid"
            ]);
        }
        
        $slots = (array) json_decode($request->get('slots'));
        if (!count($slots)) {
            return new JsonResponse([
                    'success' => false,
                    'msg' => "Slots missing"
            ]);
        }
        foreach ($slots as $card_code => $qty) {
            if (!is_string($card_code) || !is_integer($qty)) {
                return new JsonResponse([
                        'success' => false,
                        'msg' => "Slots invalid"
                ]);
            }
        }
        
        $name = filter_var($request->get('name'), FILTER_SANITIZE_STRING, FILTER_FLAG_NO_ENCODE_QUOTES);
        if (!$name) {
            return new JsonResponse([
                    'success' => false,
                    'msg' => "Name missing"
            ]);
        }
        
        $decklist_id = filter_var($request->get('decklist_id'), FILTER_SANITIZE_NUMBER_INT);
        $description = trim($request->get('description'));
        $tags = filter_var($request->get('tags'), FILTER_SANITIZE_STRING, FILTER_FLAG_NO_ENCODE_QUOTES);
        
        $this->get('deck_manager')->save($this->getUser(), $deck, $decklist_id, $name, $faction, $description, $tags, $slots, null);
        
        $this->getDoctrine()->getManager()->flush();
        
        return new JsonResponse([
                'success' => true,
                'msg' => $deck->getId()
        ]);
    }

    /**
     * Try to publish one Deck of the authenticated user
     * If publication is successful, update the version of the deck and return the id of the decklist
     *
     * @Operation(
     *     tags={"Deck"},
     *     summary="Publish One Deck",
     *     @SWG\Parameter(
     *         name="description_md",
     *         in="body",
     *         description="Description of the Decklist in Markdown",
     *         required=false,
     *         type="string",
     *         schema=""
     *     ),
     *     @SWG\Parameter(
     *         name="tournament_id",
     *         in="body",
     *         description="Identifier of the Tournament type of the Decklist",
     *         required=false,
     *         type="integer",
     *         schema=""
     *     ),
     *     @SWG\Parameter(
     *         name="precedent_id",
     *         in="body",
     *         description="Identifier of the Predecessor of the Decklist",
     *         required=false,
     *         type="integer",
     *         schema=""
     *     ),
     *     @SWG\Response(
     *         response="200",
     *         description="Returned when successful"
     *     )
     * )
     *
     * @param Request $request
     */
    public function publishDeckAction($id, Request $request)
    {
        /* @var $deck \AppBundle\Entity\Deck */
        $deck = $this->getDoctrine()->getRepository('AppBundle:Deck')->find($id);
        if ($this->getUser()->getId() !== $deck->getUser()->getId()) {
            throw $this->createAccessDeniedException("Access denied to this object.");
        }
        
        $name = filter_var($request->request->get('name'), FILTER_SANITIZE_STRING, FILTER_FLAG_NO_ENCODE_QUOTES);
        $descriptionMd = trim($request->request->get('description_md'));
        
        $tournament_id = intval(filter_var($request->request->get('tournament_id'), FILTER_SANITIZE_NUMBER_INT));
        $tournament = $this->getDoctrine()->getManager()->getRepository('AppBundle:Tournament')->find($tournament_id);

        $precedent_id = trim($request->request->get('precedent'));
        if (!preg_match('/^\d+$/', $precedent_id)) {
            // route decklist_detail hard-coded
            if (preg_match('/view\/(\d+)/', $precedent_id, $matches)) {
                $precedent_id = $matches[1];
            } else {
                $precedent_id = null;
            }
        }
        $precedent = $precedent_id ? $em->getRepository('AppBundle:Decklist')->find($precedent_id) : null;
        
        try {
            $decklist = $this->get('decklist_factory')->createDecklistFromDeck($deck, $name, $descriptionMd);
        } catch (\Exception $e) {
            return new JsonResponse([
                    'success' => false,
                    'msg' => $e->getMessage()
            ]);
        }
        
        $decklist->setTournament($tournament);
        $decklist->setPrecedent($precedent);
        $this->getDoctrine()->getManager()->persist($decklist);
        $this->getDoctrine()->getManager()->flush();

        return new JsonResponse([
                'success' => true,
                'msg' => $decklist->getId()
        ]);
    }
}
